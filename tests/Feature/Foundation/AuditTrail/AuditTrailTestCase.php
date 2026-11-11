<?php

namespace Tests\Feature\Foundation\AuditTrail;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Foundation\FoundationTestCase;
use App\Foundation\RolesPermissions\Entities\Role;
use Tests\Feature\Foundation\WithRolePermissionFixtures;

abstract class AuditTrailTestCase extends FoundationTestCase
{
    use WithRolePermissionFixtures;

    protected Company $companyA;

    protected Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();
        config(['app.debug' => false]);
        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();
    }

    protected function aConsentingMember(Company $company, string $roleCode): User
    {
        $member = User::factory()->create();
        $this->attachMember($company, $member, Role::query()->where('code', $roleCode)->sole());
        $this->aConsentRecordedBy($member, $company);

        return $member;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function viewAuditTrail(?User $actor, Company $company, array $query = []): TestResponse
    {
        if ($actor !== null) {
            Sanctum::actingAs($actor);
        }

        return $this->getJson('/api/v1/audit-trail?'.http_build_query($query), ['X-Company-Context' => $company->getKey()]);
    }

    protected function sendToAuditTrail(string $verb, User $actor, string $path): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->json($verb, $path, [], ['X-Company-Context' => $this->companyA->getKey()]);
    }

    protected function assertRoleInsufficient(TestResponse $response): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'ROLE_INSUFFICIENT');
        $response->assertJsonPath('message', 'You do not have permission to perform this action.');
        $response->assertJsonPath('required_permission', 'audit.view');
    }

    protected function assertUnauthenticated(TestResponse $response): void
    {
        $response->assertUnauthorized();
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $response->assertJsonMissingPath('data');
    }
}
