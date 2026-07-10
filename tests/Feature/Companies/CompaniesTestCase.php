<?php

namespace Tests\Feature\Companies;

use Tests\TestCase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Companies\Entities\CompanySettings;
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
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * The requested resource does not exist — 404 with the §S21 envelope and no
     * existence leak (AuthorizeCompanyTest mirror; module bases are independent).
     */
    protected function assertResourceNotFound(TestResponse $response): void
    {
        $response->assertNotFound();
        $response->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $response->assertJsonPath('message', 'The requested resource was not found.');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * The member's role lacks the required permission — 403 ROLE_INSUFFICIENT
     * naming the missing grant (TwoGateCheckTest::assertPermissionDenied mirror).
     */
    protected function assertRoleInsufficient(TestResponse $response, string $permission): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'ROLE_INSUFFICIENT');
        $response->assertJsonPath('required_permission', $permission);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * The exact UC-1-032 CompanyResource wire shape for $company — read back
     * through the models so casts govern the expected types. B7 teeth: no
     * employee_count / legal_hold / owner_id keys exist to be expected.
     *
     * @return array<string, mixed>
     */
    protected function expectedCompanyDetails(Company $company): array
    {
        $company->refresh();
        $settings = CompanySettings::query()->where('company_id', $company->getKey())->sole();

        return [
            'id' => $company->getKey(),
            'name' => $company->name,
            'br_number' => $company->br_number,
            'epf_number' => $company->epf_number,
            'country' => $company->country,
            'plan' => $company->current_billing_plan?->value,
            'status' => $company->status->value,
            'trial_ends_at' => $company->trial_ends_at?->toISOString(),
            'settings' => [
                'timezone' => $settings->timezone,
                'currency' => $settings->currency,
                'locale_default' => $settings->locale_default,
                'fiscal_year_start_month' => $settings->fiscal_year_start_month,
                'working_days_per_week' => $settings->working_days_per_week,
                'default_work_hours_per_day' => $settings->default_work_hours_per_day,
            ],
        ];
    }
}
