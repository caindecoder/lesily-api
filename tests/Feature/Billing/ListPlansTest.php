<?php

namespace Tests\Feature\Billing;

use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
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

    // Edge — the catalog's primary consumer is the trial company (Day-31 modal /
    // upgrade picker): current_billing_plan is NULL during trial (s066 ruling),
    // and the read is permission-gated only — never plan- or feature-gated.
    #[Test]
    public function when_member_of_trial_company_requests_plans_then_catalog_returned(): void
    {
        // Given an owner of a trial company (no billing plan set)
        $company = Company::factory()->withoutPlan()->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they request the plan catalog
        $response = $this->viewPlansAs($owner, $company);

        // Then the SAME full-priced catalog a paid company sees is returned
        // (payload identity, not just shape — pr-test-analyzer 4a)
        $this->assertFullCatalogWithPrices($response);
    }

    // Happy — every role the 2026-05-24 matrix grants billing.view (owner ✅,
    // admin ✅ operational visibility, auditor 👁️ read-only) sees the catalog.
    #[Test]
    #[DataProvider('provideViewingRoles')]
    public function when_role_with_billing_view_requests_plans_then_catalog_returned(string $roleCode): void
    {
        // Given a company member whose seeded role holds billing.view
        [$company, $member] = $this->aCompanyWithMember($roleCode);

        // When they request the plan catalog
        $response = $this->viewPlansAs($member, $company);

        // Then the full four-tier catalog is returned
        $this->assertCatalogReturned($response);
    }

    // Happy — the exact designed contract: §10 prices as LKR integer cents
    // (Free 0 · Starter 350 monthly-only · Pro 600|510 annual · Enterprise
    // 750|600 annual, ×100), chunk-1 feature lists, and NO minimums key —
    // §10 L261 min-5 is ToS-only, never advertised (R9); assertExactJson
    // proves the omission.
    #[Test]
    public function when_owner_requests_plans_then_full_catalog_with_prices_returned(): void
    {
        // Given an owner of a company
        [$company, $owner] = $this->aCompanyWithMember('owner');

        // When they request the plan catalog
        $response = $this->viewPlansAs($owner, $company);

        // Then the exact four-tier contract is returned
        $this->assertFullCatalogWithPrices($response);
    }

    /**
     * The catalog matches the designed contract exactly — nothing extra leaks
     * (no minimums, no pagination meta), every price is the §10 figure in
     * LKR integer cents, annual is null where no annual option exists.
     */
    protected function assertFullCatalogWithPrices(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertExactJson(['data' => [
            [
                'code' => 'free',
                'label' => 'Free',
                'features' => [],
                'monthly_price_cents' => 0,
                'annual_price_cents' => null,
                'currency' => 'LKR',
            ],
            [
                'code' => 'starter',
                'label' => 'Starter',
                'features' => ['employee_directory'],
                'monthly_price_cents' => 35000,
                'annual_price_cents' => null,
                'currency' => 'LKR',
            ],
            [
                'code' => 'pro',
                'label' => 'Pro',
                'features' => ['employee_directory', 'two_factor_auth'],
                'monthly_price_cents' => 60000,
                'annual_price_cents' => 51000,
                'currency' => 'LKR',
            ],
            [
                'code' => 'enterprise',
                'label' => 'Enterprise',
                'features' => ['employee_directory', 'two_factor_auth'],
                'monthly_price_cents' => 75000,
                'annual_price_cents' => 60000,
                'currency' => 'LKR',
            ],
        ]]);
    }

    /** @return array<string, array{string}> */
    public static function provideViewingRoles(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
            'auditor' => ['auditor'],
        ];
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
