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
     * A fresh user made a live member of $company under a seeded role — the
     * company_user pivot row the billing.view gate reads. Duplicated from
     * CompaniesTestCase (module bases are independent — established precedent).
     */
    protected function aCompanyMember(Company $company, string $roleCode = 'employee'): User
    {
        $user = User::factory()->create();

        $company->users()->attach($user, ['role_id' => $this->seededRole($roleCode)->getKey()]);

        return $user;
    }

    /**
     * A company on a paid plan with one fresh member under a seeded role.
     *
     * @return array{0: Company, 1: User}
     */
    protected function aCompanyWithMember(string $roleCode): array
    {
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();

        return [$company, $this->aCompanyMember($company, $roleCode)];
    }

    /**
     * The plan catalog came back — 200 with all four standard tiers in the
     * non-paginated {data: [...]} envelope, each row carrying the six contract
     * keys (LD assertJsonCount shape-lock pattern).
     */
    protected function assertCatalogReturned(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertJsonCount(4, 'data');
        $response->assertJsonStructure(['data' => ['*' => [
            'code', 'label', 'features', 'monthly_price_cents', 'annual_price_cents', 'currency',
        ]]]);
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
     * An authenticated member requests the company's subscription state under
     * $company's context.
     */
    protected function viewSubscriptionAs(User $user, Company $company): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/subscription', ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * A guest (no token) requests the company's subscription state.
     */
    protected function viewSubscriptionAsGuest(): TestResponse
    {
        return $this->getJson('/api/v1/subscription');
    }

    /**
     * The request was rejected as unauthenticated — 401 with the §S21
     * UNAUTHENTICATED envelope. Duplicated from CompaniesTestCase/AuthTestCase
     * (module bases are independent — established precedent; base-level
     * consolidation stays a flagged future refactor).
     */
    protected function assertUnauthenticated(TestResponse $response): void
    {
        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
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
