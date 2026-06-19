<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use App\Companies\Enums\CompanyStatus;
use PHPUnit\Framework\Attributes\Test;
use App\Consent\Events\ConsentRecorded;
use Illuminate\Support\Facades\Notification;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class RegisterOwnerTest extends AuthTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Registration fires the welcome + verification reactors (genesis side
        // effects); spy them at the boundary so this suite stays about the account.
        Notification::fake();

        // The owner pivot resolves Role::findByCode('owner') from the real
        // UC-1-136 catalog; production runs the seeder at deploy.
        $this->seedRbacCatalog();
        $this->travelTo('2026-06-08 00:00:00');
    }

    // Happy — the walking skeleton: one POST stands up the whole owner account.
    #[Test]
    public function when_a_valid_owner_registers_then_the_company_starts_its_trial_with_the_owner_enrolled(): void
    {
        // Given a guest with the full signup payload
        // When the guest registers as owner
        $response = $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload());

        // Then the owner account is stood up atomically
        $this->assertRegistrationSucceeded($response);
        $this->assertOwnerAccountProjected('nimal@contoso.lk');
        $this->assertCompanyOnTrialWithOwner('nimal@contoso.lk');
        $this->assertOwnerEnrolledWithEmergencyContact('nimal@contoso.lk');
        $this->assertConsentRecorded('nimal@contoso.lk');
        $this->assertCompanySettingsSeeded('nimal@contoso.lk');
    }

    // Happy — the issued token is a real credential: it authenticates the new owner
    // straight through auth:sanctum + auth.company (#03) with the fresh membership.
    #[Test]
    public function when_the_registration_token_is_used_then_it_authenticates_the_new_owner(): void
    {
        // Given a freshly registered owner and the token + company from the response
        $registration = $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload());
        $token = $registration->json('data.token');
        $companyId = $registration->json('data.company.id');

        // When the token is used against a company-scoped route
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/_test/company-context-probe', ['X-Company-Context' => $companyId]);

        // Then it authenticates the owner and binds their company
        $response->assertOk();
        $response->assertJsonPath('company_id', $companyId);
    }

    // Happy — a double-submitted signup (double-click / network retry) carrying an
    // Idempotency-Key replays the first account instead of hitting the uniqueness
    // backstop (OQ-1-114 register optional mount).
    #[Test]
    public function when_an_owner_signup_is_double_submitted_with_a_key_then_the_account_is_returned_without_a_duplicate(): void
    {
        // Given a guest signs up carrying an Idempotency-Key
        $payload = $this->validRegistrationPayload();
        $headers = ['Idempotency-Key' => 'a1b2c3d4-signup'];
        $first = $this->postJson('/api/v1/auth/register', $payload, $headers);

        // When the identical signup is submitted again with the same key
        $second = $this->postJson('/api/v1/auth/register', $payload, $headers);

        // Then the original account is replayed and no duplicate company is created
        $this->assertReplayedWithoutDuplicate($first, $second);
    }

    protected function assertReplayedWithoutDuplicate(TestResponse $first, TestResponse $second): void
    {
        $first->assertCreated();
        $second->assertCreated();
        $second->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json('data.company.id'), $second->json('data.company.id'));
        $this->assertSame(1, Company::query()->count());
    }

    protected function assertRegistrationSucceeded(TestResponse $response): void
    {
        $response->assertCreated();
        $response->assertJsonStructure(['data' => ['token', 'user' => ['id'], 'company' => ['id']]]);
        $response->assertJsonPath('data.user.name', 'Nimal Perera');
        $response->assertJsonPath('data.user.email', 'nimal@contoso.lk');
    }

    protected function assertOwnerAccountProjected(string $email): void
    {
        $user = User::query()->where('email', $email)->sole();

        $this->assertSame('Nimal Perera', $user->name);
        $this->assertTrue(Hash::check('S3cure-Pass!', $user->password));
    }

    protected function assertCompanyOnTrialWithOwner(string $ownerEmail): void
    {
        $owner = User::query()->where('email', $ownerEmail)->sole();
        $company = Company::query()->where('owner_id', $owner->id)->sole();

        $this->assertSame('Contoso', $company->name);
        $this->assertSame(CompanyStatus::Trial, $company->status);
        $this->assertSame('2026-07-08 00:00:00', $company->trial_ends_at?->toDateTimeString());
        $this->assertDatabaseHas('subscriptions', [
            'company_id' => $company->id,
            'status' => 'trial',
            'trial_ends_at' => '2026-07-08 00:00:00',
        ]);
    }

    protected function assertConsentRecorded(string $ownerEmail): void
    {
        $owner = User::query()->where('email', $ownerEmail)->sole();
        $company = Company::query()->where('owner_id', $owner->id)->sole();

        // One version-stamped record per policy type (UC-1-069); sole() doubles
        // as the exactly-one-per-type count assertion, and the total bounds the set.
        $this->assertSame(2, LesilyStoredEvent::query()
            ->whereForCompany($company->id)
            ->where('event_class', ConsentRecorded::class)
            ->count());

        foreach (['tos', 'privacy'] as $policyType) {
            $consent = LesilyStoredEvent::query()
                ->whereForCompany($company->id)
                ->where('event_class', ConsentRecorded::class)
                ->where('event_properties->policyType', $policyType)
                ->sole();

            $this->assertSame('v1.0', $consent->event_properties['version']);
            $this->assertSame($owner->id, $consent->event_properties['userId']);
            $this->assertSame('127.0.0.1', $consent->event_properties['ip']);
        }
    }

    protected function assertCompanySettingsSeeded(string $ownerEmail): void
    {
        $owner = User::query()->where('email', $ownerEmail)->sole();
        $company = Company::query()->where('owner_id', $owner->id)->sole();

        $this->assertDatabaseHas('company_settings', [
            'company_id' => $company->id,
            'currency' => 'LKR',
            'timezone' => 'Asia/Colombo',
            'locale_default' => 'en',
            'fiscal_year_start_month' => 1,
            'working_days_per_week' => 5,
            'default_work_hours_per_day' => 8.0,
        ]);
    }

    protected function assertOwnerEnrolledWithEmergencyContact(string $ownerEmail): void
    {
        $owner = User::query()->where('email', $ownerEmail)->sole();
        $company = Company::query()->where('owner_id', $owner->id)->sole();
        $ownerRole = Role::findByCode('owner');

        $this->assertNotNull($ownerRole);
        $this->assertDatabaseHas('company_user', [
            'user_id' => $owner->id,
            'company_id' => $company->id,
            'role_id' => $ownerRole->getKey(),
            'emergency_contact_name' => 'Kamala Perera',
            'emergency_contact_email' => 'kamala@contoso.lk',
            'emergency_contact_phone' => '+94771234567',
            'emergency_contact_relationship' => 'spouse',
        ]);
    }
}
