<?php

namespace Tests\Feature\Billing;

use Tests\TestCase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Foundation\RolesPermissions\Entities\Role;

abstract class BillingTestCase extends TestCase
{
    /**
     * Look up a seeded RBAC-catalog role by code — requires seedRbacCatalog() in
     * the calling suite's setUp. Duplicated from CompaniesTestCase/AuthTestCase
     * (module bases are independent — established precedent).
     */
    protected function seededRole(string $code): Role
    {
        return Role::query()->where('code', $code)->sole();
    }

    /**
     * A company on a paid plan with one fresh member under a seeded role — the
     * company_user pivot row the billing.view gate reads.
     *
     * @return array{0: Company, 1: User}
     */
    protected function aCompanyWithMember(string $roleCode): array
    {
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = User::factory()->create();

        $company->users()->attach($user, ['role_id' => $this->seededRole($roleCode)->getKey()]);

        return [$company, $user];
    }

    /**
     * An authenticated member requests the plan catalog under $company's context.
     */
    protected function viewPlansAs(User $user, Company $company): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/plans', ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * The member's role lacks the required permission — 403 ROLE_INSUFFICIENT
     * naming the missing grant. Duplicated from CompaniesTestCase (module bases
     * are independent — established precedent).
     */
    protected function assertRoleInsufficient(TestResponse $response, string $permission): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'ROLE_INSUFFICIENT');
        $response->assertJsonPath('required_permission', $permission);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }
}
