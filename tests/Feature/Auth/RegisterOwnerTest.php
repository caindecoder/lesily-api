<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use App\Companies\Enums\CompanyStatus;
use PHPUnit\Framework\Attributes\Test;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RegisterOwnerTest extends AuthTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The owner pivot resolves Role::findByCode('owner') from the real
        // UC-1-136 catalog; production runs the seeder at deploy.
        $this->seedRbacCatalog();
        $this->travelTo('2026-06-08 00:00:00');
    }

    protected function seedRbacCatalog(): void
    {
        (new RolesPermissionsSeeder(
            rolesYamlPath: database_path('seeders/data/roles.yaml'),
            permissionsYamlPath: database_path('seeders/data/permissions.yaml'),
            rolePermissionsYamlPath: database_path('seeders/data/role-permissions.yaml'),
        ))->run();
    }

    // Happy — the walking skeleton: one POST stands up the whole owner account.
    #[Test]
    public function when_a_valid_owner_registers_then_the_company_starts_its_trial_with_the_owner_enrolled(): void
    {
        // Given a guest with the full signup payload
        // When the guest registers as owner
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Nimal Perera',
            'email' => 'nimal@contoso.lk',
            'password' => 'S3cure-Pass!',
            'acceptedTos' => true,
            'companyName' => 'Contoso',
            'brNumber' => 'PV-12345678',
            'epfNumber' => 'A-1234567',
            'country' => 'LK',
            'emergencyContactName' => 'Kamala Perera',
            'emergencyContactEmail' => 'kamala@contoso.lk',
            'emergencyContactPhone' => '+94771234567',
            'emergencyContactRelationship' => 'spouse',
        ]);

        // Then the owner account is stood up atomically
        $this->assertRegistrationSucceeded($response);
        $this->assertOwnerAccountProjected('nimal@contoso.lk');
        $this->assertCompanyOnTrialWithOwner('nimal@contoso.lk');
    }

    protected function assertRegistrationSucceeded(TestResponse $response): void
    {
        $response->assertCreated();
        $response->assertJsonStructure(['data' => ['token', 'user' => ['id'], 'company' => ['id']]]);
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
        $this->assertSame('2026-07-08 00:00:00', $company->trial_ends_at->toDateTimeString());
        $this->assertDatabaseHas('subscriptions', ['company_id' => $company->id, 'status' => 'trial']);
    }
}
