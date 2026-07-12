<?php

namespace Tests\Feature\Billing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * UC-1-042 (partial — plan-picker read, slice-plans-billing T1).
 * GET /api/v1/plans — permission-gated (billing.view), enum-derived static
 * catalog, non-paginated {data: [...]} shape, no minimums (R9).
 */
class ListPlansTest extends BillingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();

        // Error-path tests must exercise the production render path (the
        // bootstrap catch-all behaves differently under debug — amendment M).
        config(['app.debug' => false]);
    }

    // Sad — the catalog is billing.view-gated: a member whose seeded role lacks
    // the grant is refused. The payroll_officer row is the F2/D-A oracle — the
    // 2026-05-24 matrix says ❌ while the old seed granted it.
    #[Test]
    #[DataProvider('provideDeniedRoles')]
    public function when_role_without_billing_view_requests_plans_then_role_insufficient(string $roleCode): void
    {
        // Given a company member whose role lacks billing.view
        [$company, $member] = $this->aCompanyWithMember($roleCode);

        // When they request the plan catalog
        $response = $this->viewPlansAs($member, $company);

        // Then the request is refused naming the missing grant
        $this->assertRoleInsufficient($response, 'billing.view');
    }

    // Sad — the catalog is auth-gated: a guest gets the standard unauthenticated
    // refusal (the catalog is not public — declared LD divergence, spec §6).
    #[Test]
    public function when_request_has_no_token_then_unauthenticated(): void
    {
        // Given an existing company and no authenticated session
        [$company] = $this->aCompanyWithMember('owner');

        // When a guest requests the plan catalog
        $response = $this->getJson('/api/v1/plans', ['X-Company-Context' => $company->getKey()]);

        // Then the request is rejected as unauthenticated
        $this->assertUnauthenticated($response);
    }

    // Sad — the company-scope gate runs ahead of the permission gate: an owner of
    // Company A probing under Company B's context is refused as a non-member,
    // regardless of their own role's grants.
    #[Test]
    public function when_actor_uses_foreign_company_context_then_company_access_denied(): void
    {
        // Given an owner of Company A and a foreign Company B
        [, $owner] = $this->aCompanyWithMember('owner');
        [$foreignCompany] = $this->aCompanyWithMember('owner');

        // When they request the plan catalog under Company B's context
        $response = $this->viewPlansAs($owner, $foreignCompany);

        // Then company access is denied
        $this->assertCompanyAccessDenied($response);
    }

    /** @return array<string, array{string}> */
    public static function provideDeniedRoles(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'manager' => ['manager'],
        ];
    }
}
