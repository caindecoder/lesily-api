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
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Foundation\Auth\UseCases\RegistrationUniquenessGuard;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerCommand;
use App\Foundation\Auth\Exceptions\RegistrationConflictException;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class RegisterOwnerTest extends AuthTestCase
{
    private const EXISTING_EMAIL = 'existing@contoso.lk';

    private const EXISTING_BR = 'PV-99999999';

    private const EXISTING_EPF = 'A-999999';

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

    // Sad (validation) — a malformed payload is rejected before any aggregate runs.
    #[Test]
    #[DataProvider('invalidPayloadProvider')]
    public function when_the_registration_payload_is_invalid_then_it_is_rejected_and_nothing_is_created(array $override, string $invalidField, string $expectedMessage): void
    {
        // Given an otherwise-valid signup with one invalid field
        $payload = [...$this->validRegistrationPayload(), ...$override];

        // When the guest submits it
        $response = $this->postJson('/api/v1/auth/register', $payload);

        // Then registration is rejected, the offending field is reported and nothing is created or sent
        $this->assertRejectedAndNothingCreated($response, $invalidField, $expectedMessage);
    }

    /**
     * Each row blanks, nulls or corrupts one field of an otherwise-valid payload and
     * pins the rule's verbatim message.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'missing password' => [['password' => ''], 'password', 'The password field is required.'],
            'weak password' => [['password' => 'short'], 'password', 'Password must be at least 8 characters.'],
            'terms null' => [['acceptedTos' => null], 'acceptedTos', 'You must agree to the Terms of Service to continue.'],
            'terms not accepted' => [['acceptedTos' => false], 'acceptedTos', 'You must agree to the Terms of Service to continue.'],
            'missing name' => [['name' => ''], 'name', 'The name field is required.'],
            'over-long name' => [['name' => str_repeat('a', 256)], 'name', 'The name field must not be greater than 255 characters.'],
            'missing email' => [['email' => ''], 'email', 'The email field is required.'],
            'owner email not an email' => [['email' => 'nimal-at-contoso'], 'email', 'The email field must be a valid email address.'],
            'missing company name' => [['companyName' => ''], 'companyName', 'The company name field is required.'],
            'over-long company name' => [['companyName' => str_repeat('a', 256)], 'companyName', 'The company name field must not be greater than 255 characters.'],
            'missing BR number' => [['brNumber' => ''], 'brNumber', 'The BR (Business Registration) number is required.'],
            'over-long BR number' => [['brNumber' => str_repeat('b', 256)], 'brNumber', 'The br number field must not be greater than 255 characters.'],
            'missing EPF number' => [['epfNumber' => ''], 'epfNumber', 'The EPF employer number is required.'],
            'EPF with two letters' => [['epfNumber' => 'AB/12345'], 'epfNumber', 'Invalid EPF number format.'],
            'EPF with seven digits' => [['epfNumber' => 'A/1234567'], 'epfNumber', 'Invalid EPF number format.'],
            'EPF with no letter' => [['epfNumber' => '12345'], 'epfNumber', 'Invalid EPF number format.'],
            'over-long EPF number' => [['epfNumber' => str_repeat('e', 256)], 'epfNumber', 'The epf number field must not be greater than 255 characters.'],
            'missing country' => [['country' => ''], 'country', 'The country field is required.'],
            'country beyond two characters' => [['country' => 'LKA'], 'country', 'The country field must be 2 characters.'],
            'missing emergency contact name' => [['emergencyContactName' => ''], 'emergencyContactName', 'The emergency contact name field is required.'],
            'missing emergency contact email' => [['emergencyContactEmail' => ''], 'emergencyContactEmail', 'The emergency contact email field is required.'],
            'emergency contact email not an email' => [['emergencyContactEmail' => 'kamala-at-contoso'], 'emergencyContactEmail', 'The emergency contact email must be a valid email address.'],
            'missing emergency contact phone' => [['emergencyContactPhone' => ''], 'emergencyContactPhone', 'The emergency contact phone field is required.'],
            'phone not a number' => [['emergencyContactPhone' => '12345'], 'emergencyContactPhone', 'The emergency contact phone must be a valid phone number.'],
            'phone outside Sri Lanka' => [['emergencyContactPhone' => '+442071838750'], 'emergencyContactPhone', 'The emergency contact phone must be a valid phone number.'],
            'missing relationship' => [['emergencyContactRelationship' => ''], 'emergencyContactRelationship', 'The emergency contact relationship field is required.'],
            'relationship not on the list' => [['emergencyContactRelationship' => 'cousin'], 'emergencyContactRelationship', 'The selected emergency contact relationship is invalid.'],
        ];
    }

    // Sad (conflict) — a globally-unique identifier already in use is rejected
    // with 409 (well-formed but conflicts with current state — api-conventions §6),
    // and nothing new is persisted.
    #[Test]
    #[DataProvider('duplicateFieldProvider')]
    public function when_a_registration_field_is_already_registered_then_it_conflicts(array $override, string $expectedCode): void
    {
        // Given an account already holds these identifiers
        $this->givenAnExistingOwnerAccount();
        $usersBefore = User::query()->count();
        $companiesBefore = Company::query()->count();

        // When a guest registers colliding on one of them
        $response = $this->postJson('/api/v1/auth/register', [...$this->validRegistrationPayload(), ...$override]);

        // Then the registration conflicts, creates nothing new and welcomes nobody
        $this->assertConflict($response, $expectedCode);
        $this->assertNothingCreatedOrSent($usersBefore, $companiesBefore);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function duplicateFieldProvider(): array
    {
        return [
            'email' => [['email' => self::EXISTING_EMAIL], 'EMAIL_REGISTERED'],
            'business registration number' => [['brNumber' => self::EXISTING_BR], 'BR_NUMBER_REGISTERED'],
            'employer provident fund number' => [['epfNumber' => self::EXISTING_EPF], 'EPF_NUMBER_REGISTERED'],
        ];
    }

    // Sad (conflict precedence) — when several identifiers collide at once, the
    // guard reports the first in its own order (email, then BR, then EPF).
    #[Test]
    #[DataProvider('collidingFieldsProvider')]
    public function when_several_registration_fields_are_already_registered_then_the_first_conflict_in_guard_order_is_reported(array $override, string $expectedCode): void
    {
        // Given an account already holds these identifiers
        $this->givenAnExistingOwnerAccount();
        $usersBefore = User::query()->count();
        $companiesBefore = Company::query()->count();

        // When a guest registers colliding on two of them at once
        $response = $this->postJson('/api/v1/auth/register', [...$this->validRegistrationPayload(), ...$override]);

        // Then the first conflict in guard order is reported and nothing is created or sent
        $this->assertConflict($response, $expectedCode);
        $this->assertNothingCreatedOrSent($usersBefore, $companiesBefore);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function collidingFieldsProvider(): array
    {
        return [
            'email and BR both taken' => [['email' => self::EXISTING_EMAIL, 'brNumber' => self::EXISTING_BR], 'EMAIL_REGISTERED'],
            'BR and EPF both taken' => [['brNumber' => self::EXISTING_BR, 'epfNumber' => self::EXISTING_EPF], 'BR_NUMBER_REGISTERED'],
        ];
    }

    // Sad — a registration whose client IP can't be resolved (an empty REMOTE_ADDR) is rejected
    // with a clean 422, NOT a 500, and no consent record is minted without its IP.
    #[Test]
    public function when_the_client_ip_is_undeterminable_then_registration_is_rejected_as_invalid(): void
    {
        // Given a guest whose request carries no resolvable client IP
        // When they register
        $response = $this->withServerVariables(['REMOTE_ADDR' => ''])
            ->postJson('/api/v1/auth/register', $this->validRegistrationPayload());

        // Then it is rejected as a validation error (a clean 4xx), not a server crash,
        // and the refusal happens before anything is written — the guard sits ahead of
        // the transaction, so a relocation below it would leave an orphan account
        $this->assertValidationRejected($response, 'ip', 'The ip field is required.');
        $this->assertNothingPersisted();
    }

    // Edge (race backstop) — two concurrent signups can both pass the pre-tx
    // check-then-act guard; the second to persist hits the DB UNIQUE constraint.
    // That backstop must surface the same field-specific 409, never a 500.
    #[Test]
    #[DataProvider('duplicateFieldProvider')]
    public function when_a_race_slips_past_the_uniqueness_guard_then_the_database_backstop_still_conflicts(array $override, string $expectedCode): void
    {
        // Given an account already holds these identifiers, and the pre-tx guard is
        // bypassed to reproduce the concurrent race that slips past check-then-act
        $this->givenAnExistingOwnerAccount();
        $this->bypassUniquenessGuard();
        $usersBefore = User::query()->count();
        $companiesBefore = Company::query()->count();

        // When a guest registers colliding on one of them
        $response = $this->postJson('/api/v1/auth/register', [...$this->validRegistrationPayload(), ...$override]);

        // Then the database backstop conflicts identically and creates nothing new
        $this->assertConflict($response, $expectedCode);
        $this->assertNoNewAccounts($usersBefore, $companiesBefore);
    }

    // Edge (re-raise) — a unique violation the translator cannot map (an index
    // outside email/BR/EPF) must surface as the original 500, never be masked as a
    // registration 409. Bypassing the guard AND nulling the translation forces the
    // backstop down the unmapped path while a real duplicate still trips the index.
    #[Test]
    public function when_the_backstop_cannot_map_the_violation_then_the_original_error_is_not_masked(): void
    {
        // Given the pre-tx guard is bypassed and the translator maps nothing
        config(['app.debug' => false]);
        $this->givenAnExistingOwnerAccount();
        $this->app->instance(RegistrationUniquenessGuard::class, new class extends RegistrationUniquenessGuard
        {
            public function ensureUnique(RegisterOwnerCommand $command): void {}

            public function conflictFor(UniqueConstraintViolationException $violation): ?RegistrationConflictException
            {
                return null;
            }
        });
        $usersBefore = User::query()->count();
        $companiesBefore = Company::query()->count();

        // When a guest collides on email so the DB UNIQUE constraint genuinely fires
        $response = $this->postJson('/api/v1/auth/register', [...$this->validRegistrationPayload(), 'email' => self::EXISTING_EMAIL]);

        // Then the original database fault surfaces, never a masked conflict
        $response->assertInternalServerError();
        $response->assertJsonPath('error_code', 'INTERNAL_ERROR');
        $this->assertNoNewAccounts($usersBefore, $companiesBefore);
    }

    // Edge (atomic rollback) — an unsupported country passes shape validation but
    // throws mid-transaction (after the owner is written), proving the whole
    // registration rolls back as one unit.
    #[Test]
    public function when_an_unsupported_country_is_submitted_then_nothing_is_persisted(): void
    {
        // Given a signup for a country with no seeded defaults
        $payload = [...$this->validRegistrationPayload(), 'country' => 'ZZ'];

        // When the guest submits it
        $response = $this->postJson('/api/v1/auth/register', $payload);

        // Then the request is rejected and the half-built account is rolled back
        $response->assertUnprocessable();
        $response->assertJsonPath('error_code', 'UNSUPPORTED_COUNTRY');
        $this->assertNothingPersisted();
    }

    // Edge (rate limit) — the public register endpoint runs under the auth-tier
    // per-IP limit (10/min) so credential-stuffing and signup abuse are throttled.
    #[Test]
    public function when_registration_attempts_exceed_the_auth_rate_limit_then_they_are_throttled(): void
    {
        // Given the per-IP auth quota for the minute is exhausted
        $this->exhaustAuthLimit(10, '/api/v1/auth/register');

        // When another registration is attempted
        $response = $this->postJson('/api/v1/auth/register', []);

        // Then it is throttled with retry guidance
        $this->assertThrottled($response);
    }

    // Edge (rate limit) — the limit is keyed per-IP, so one abusive client can't
    // throttle signups for everyone (the limiter is excluded from mutation testing,
    // so this behavioural test is the only guard on the ->by(ip) key).
    #[Test]
    public function when_a_separate_ip_registers_then_it_is_unaffected_by_another_ips_throttle(): void
    {
        // Given one IP has exhausted its auth quota for the minute
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1']);
        $this->exhaustAuthLimit(10, '/api/v1/auth/register');
        $this->postJson('/api/v1/auth/register', [])->assertTooManyRequests();

        // When a different IP makes its first attempt
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])
            ->postJson('/api/v1/auth/register', []);

        // Then it is not throttled by the other IP's exhausted quota
        $response->assertUnprocessable();
    }

    #[Test]
    #[DataProvider('acceptedValueProvider')]
    public function when_a_field_takes_an_accepted_form_then_the_owner_is_registered(array $override): void
    {
        // Given a signup whose field takes one of its accepted forms
        $payload = [...$this->validRegistrationPayload(), ...$override];

        // When the guest submits it
        $response = $this->postJson('/api/v1/auth/register', $payload);

        // Then the owner is registered
        $this->assertRegistrationSucceeded($response);
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function acceptedValueProvider(): array
    {
        return [
            'EPF as on the registration form' => [['epfNumber' => 'A/099999']],
            'EPF with a space' => [['epfNumber' => 'A 85000']],
            'EPF with a hyphen' => [['epfNumber' => 'A-12345']],
            'EPF run together' => [['epfNumber' => 'A12345']],
            'EPF in lower case' => [['epfNumber' => 'a/12345']],
            'phone in local form' => [['emergencyContactPhone' => '0712345678']],
            'phone with the country code' => [['emergencyContactPhone' => '+94712345678']],
            'phone with the country code and spaces' => [['emergencyContactPhone' => '+94 71 234 5678']],
            'relationship spouse' => [['emergencyContactRelationship' => 'spouse']],
            'relationship parent' => [['emergencyContactRelationship' => 'parent']],
            'relationship sibling' => [['emergencyContactRelationship' => 'sibling']],
            'relationship business partner' => [['emergencyContactRelationship' => 'business_partner']],
            'relationship lawyer' => [['emergencyContactRelationship' => 'lawyer']],
            'relationship other' => [['emergencyContactRelationship' => 'other']],
        ];
    }

    // Edge — register is UA-derived (no deviceName on the register contract, per design):
    // a client-supplied deviceName smuggled into the body is ignored in favour of the
    // User-Agent, so the signup seam can't be used to set an arbitrary token name.
    #[Test]
    public function when_an_owner_registers_with_a_supplied_device_name_then_it_is_ignored_in_favour_of_the_user_agent(): void
    {
        // Given a guest registering from a device, also smuggling a deviceName into the body
        // When they register
        $this->withHeader('User-Agent', 'Mozilla/5.0 (TestAgent)')
            ->postJson('/api/v1/auth/register', [
                ...$this->validRegistrationPayload(),
                'deviceName' => 'Smuggled Device',
            ])->assertCreated();

        // Then the supplied name is ignored — the token is named for the User-Agent
        $owner = User::query()->where('email', 'nimal@contoso.lk')->sole();
        $this->assertTokenNamed($owner, 'Mozilla/5.0 (TestAgent)');
    }

    // Edge — registration mints the owner's first session, so the device name + "last
    // seen" stamp apply here too (register has no deviceName field — UA-derived).
    #[Test]
    public function when_an_owner_registers_then_the_token_is_named_for_the_device_and_last_logged_at_is_stamped(): void
    {
        // Given a guest registering from a device (the clock is frozen in setUp)
        // When they register
        $this->withHeader('User-Agent', 'Mozilla/5.0 (TestAgent)')
            ->postJson('/api/v1/auth/register', $this->validRegistrationPayload())
            ->assertCreated();

        // Then the owner's session token is named for the device and last_logged_at is stamped
        $owner = User::query()->where('email', 'nimal@contoso.lk')->sole();
        $this->assertTokenNamed($owner, 'Mozilla/5.0 (TestAgent)');
        $this->assertLastLoggedAtStamped($owner);
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
        $company = Company::query()->where('owner_id', $owner->getKey())->sole();

        $this->assertSame('Contoso', $company->name);
        $this->assertSame(CompanyStatus::Trial, $company->status);
        $this->assertSame('2026-07-08 00:00:00', $company->trial_ends_at?->toDateTimeString());
        $this->assertDatabaseHas('subscriptions', [
            'company_id' => $company->getKey(),
            'status' => 'trial',
            'trial_ends_at' => '2026-07-08 00:00:00',
        ]);
    }

    protected function assertConsentRecorded(string $ownerEmail): void
    {
        $owner = User::query()->where('email', $ownerEmail)->sole();
        $company = Company::query()->where('owner_id', $owner->getKey())->sole();

        // One version-stamped record per policy type (UC-1-069); sole() doubles
        // as the exactly-one-per-type count assertion, and the total bounds the set.
        $this->assertSame(2, LesilyStoredEvent::query()
            ->whereForCompany($company->getKey())
            ->where('event_class', ConsentRecorded::class)
            ->count());

        foreach (['tos', 'privacy'] as $policyType) {
            $consent = LesilyStoredEvent::query()
                ->whereForCompany($company->getKey())
                ->where('event_class', ConsentRecorded::class)
                ->where('event_properties->policyType', $policyType)
                ->sole();

            $this->assertSame('v1.0', $consent->event_properties['version']);
            $this->assertSame($owner->getKey(), $consent->event_properties['userId']);
            $this->assertSame('127.0.0.1', $consent->event_properties['ip']);
        }
    }

    protected function assertCompanySettingsSeeded(string $ownerEmail): void
    {
        $owner = User::query()->where('email', $ownerEmail)->sole();
        $company = Company::query()->where('owner_id', $owner->getKey())->sole();

        $this->assertDatabaseHas('company_settings', [
            'company_id' => $company->getKey(),
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
        $company = Company::query()->where('owner_id', $owner->getKey())->sole();
        $ownerRole = Role::findByCode('owner');

        $this->assertNotNull($ownerRole);
        $this->assertDatabaseHas('company_user', [
            'user_id' => $owner->getKey(),
            'company_id' => $company->getKey(),
            'role_id' => $ownerRole->getKey(),
            'emergency_contact_name' => 'Kamala Perera',
            'emergency_contact_email' => 'kamala@contoso.lk',
            'emergency_contact_phone' => '+94771234567',
            'emergency_contact_relationship' => 'spouse',
        ]);
    }

    protected function givenAnExistingOwnerAccount(): void
    {
        User::factory()->withEmail(self::EXISTING_EMAIL)->create();
        Company::factory()->registeredAs(self::EXISTING_BR, self::EXISTING_EPF)->create();
    }

    protected function assertConflict(TestResponse $response, string $expectedCode): void
    {
        $response->assertConflict();
        $response->assertJsonPath('error_code', $expectedCode);
        $response->assertJsonPath('errors', []);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
        $this->assertNotEmpty($response->json('correlation_id'));
    }

    protected function assertNoNewAccounts(int $usersBefore, int $companiesBefore): void
    {
        $this->assertSame($usersBefore, User::query()->count(), 'No user may be created on a conflict.');
        $this->assertSame($companiesBefore, Company::query()->count(), 'No company may be created on a conflict.');
        $this->assertDatabaseCount('stored_events', 0);
    }

    protected function assertNothingCreatedOrSent(int $usersBefore, int $companiesBefore): void
    {
        $this->assertNoNewAccounts($usersBefore, $companiesBefore);
        Notification::assertNothingSent();
    }

    protected function bypassUniquenessGuard(): void
    {
        // Replace the pre-transaction check with a no-op so the request reaches the
        // DB UNIQUE constraint — the only honest single-process way to exercise the
        // race backstop, where the conflict is detected by the database, not the guard.
        $this->app->instance(RegistrationUniquenessGuard::class, new class extends RegistrationUniquenessGuard
        {
            public function ensureUnique(RegisterOwnerCommand $command): void {}
        });
    }

    protected function assertRejectedAndNothingCreated(TestResponse $response, string $invalidField, string $expectedMessage): void
    {
        $this->assertValidationRejected($response, $invalidField, $expectedMessage);
        $this->assertNothingPersisted();
        Notification::assertNothingSent();
    }

    protected function assertNothingPersisted(): void
    {
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('company_user', 0);
        $this->assertDatabaseCount('stored_events', 0);
    }
}
