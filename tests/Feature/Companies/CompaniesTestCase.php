<?php

namespace Tests\Feature\Companies;

use Tests\TestCase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Foundation\RolesPermissions\Entities\Role;

abstract class CompaniesTestCase extends TestCase
{
    // Shared Companies fixtures populated on real need (§2.10) — first consumer:
    // the UC-1-032 view-company-details suite (slice-companies Task 5).

    /**
     * Look up a seeded RBAC-catalog role by code — requires seedRbacCatalog() in
     * the calling suite's setUp. Duplicated from MembershipsTestCase/AuthTestCase
     * (module bases are independent — established precedent).
     */
    protected function seededRole(string $code): Role
    {
        return Role::query()->where('code', $code)->sole();
    }

    /**
     * A fresh user made a live member of $company under a seeded role — the
     * company_user pivot row the company-scoped gates read.
     */
    protected function aCompanyMember(Company $company, string $roleCode = 'employee'): User
    {
        $user = User::factory()->create();

        $company->users()->attach($user, ['role_id' => $this->seededRole($roleCode)->getKey()]);

        return $user;
    }

    /**
     * An authenticated member requests a company's details by URL id. $context
     * optionally supplies the X-Company-Context header (the pre-R8 mechanism) —
     * on this param route the URL must win over it.
     */
    protected function viewCompanyDetails(User $user, string $companyId, ?string $context = null): TestResponse
    {
        Sanctum::actingAs($user);

        $headers = $context === null ? [] : ['X-Company-Context' => $context];

        return $this->getJson('/api/v1/companies/'.$companyId, $headers);
    }

    /**
     * A guest (no Sanctum session) requests a company's details by URL id.
     */
    protected function viewCompanyDetailsAsGuest(string $companyId): TestResponse
    {
        return $this->getJson('/api/v1/companies/'.$companyId);
    }

    /**
     * The request was rejected as unauthenticated — 401 with the §S21
     * UNAUTHENTICATED envelope (AuthTestCase mirror; base-level consolidation
     * is a flagged future refactor).
     */
    protected function assertUnauthenticated(TestResponse $response): void
    {
        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }
}
