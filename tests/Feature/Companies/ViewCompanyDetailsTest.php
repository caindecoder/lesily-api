<?php

namespace Tests\Feature\Companies;

use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * UC-1-032 View Company Details + the R8 CompanyContextResolver route-param
 * extension (slice-companies Task 5). GET /api/v1/companies/{companyId} —
 * param-first company resolution, membership + permission gated, exact
 * CompanyResource contract (B7 deferrals locked out by shape).
 */
class ViewCompanyDetailsTest extends CompaniesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();

        // Error-path tests must exercise the production render path (the
        // bootstrap catch-all behaves differently under debug — amendment M).
        config(['app.debug' => false]);
    }

    // Sad — the company-details read is auth-gated: a guest gets the standard
    // unauthenticated refusal and learns nothing about the company id.
    #[Test]
    public function when_unauthenticated_then_company_details_request_rejected(): void
    {
        // Given an existing company and no authenticated session
        $company = Company::factory()->create();

        // When a guest requests its details
        $response = $this->viewCompanyDetailsAsGuest($company->getKey());

        // Then the request is rejected as unauthenticated
        $this->assertUnauthenticated($response);
    }

    // Sad — R8's teeth: the URL names a company the member does not belong to. A
    // param-blind resolver would honor the X-Company-Context header and answer for
    // the WRONG company; the param must be authoritative, with the membership
    // check running against the URL company.
    #[Test]
    public function when_member_requests_another_companys_details_then_company_access_denied(): void
    {
        // Given a member of Company A holding a foreign Company B's id
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $member = $this->aCompanyMember($companyA);

        // When they request Company B's details under their own company context
        $response = $this->viewCompanyDetails($member, $companyB->getKey(), context: $companyA->getKey());

        // Then company access is denied
        $this->assertCompanyAccessDenied($response);
    }

    // Sad — an unknown URL company id is a plain 404 (resolver findOrFail on the
    // param): existence is not leaked, and a valid header cannot rescue a bad URL.
    #[Test]
    #[DataProvider('provideUnknownCompanyIds')]
    public function when_requested_company_is_unknown_then_resource_not_found(string $companyId): void
    {
        // Given a member of a real company
        $company = Company::factory()->create();
        $member = $this->aCompanyMember($company);

        // When they request details for an unknown company id under their own context
        $response = $this->viewCompanyDetails($member, $companyId, context: $company->getKey());

        // Then the resource is not found
        $this->assertResourceNotFound($response);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnknownCompanyIds(): array
    {
        return [
            'nonexistent uuid' => ['0193abcd-0000-7000-8000-000000000000'],
            'garbage string' => ['not-a-company-id'],
            'numeric value' => ['12345'],
        ];
    }

    // Sad — manager is the ONLY seeded role without companies.view (s117
    // seed-authoritative reconciliation): a manager member is refused with the
    // missing grant named.
    #[Test]
    public function when_manager_requests_company_details_then_role_insufficient(): void
    {
        // Given a manager-role member of a company
        $company = Company::factory()->create();
        $manager = $this->aCompanyMember($company, 'manager');

        // When they request their own company's details
        $response = $this->viewCompanyDetails($manager, $company->getKey());

        // Then the read is refused for the missing companies.view grant
        $this->assertRoleInsufficient($response, 'companies.view');
    }

    // Edge — a stale switcher context (the pre-R8 header mechanism) accompanies
    // a param request: the URL company wins and the header is ignored.
    #[Test]
    public function when_company_context_disagrees_with_requested_company_then_requested_company_returned(): void
    {
        // Given a member of Company B only, still carrying Company A's context header
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->withSettings()->create();
        $member = $this->aCompanyMember($companyB);

        // When they request Company B's details under Company A's stale context
        $response = $this->viewCompanyDetails($member, $companyB->getKey(), context: $companyA->getKey());

        // Then Company B's details are returned
        $response->assertOk();
        $response->assertJsonPath('data.id', $companyB->getKey());
    }

    // Edge — the URL alone carries the company claim: no X-Company-Context
    // header is required on param routes (the R8 fallback is optional, not a
    // second mandatory claim).
    #[Test]
    public function when_no_company_context_accompanies_the_request_then_requested_company_returned(): void
    {
        // Given a member of a company
        $company = Company::factory()->withSettings()->create();
        $member = $this->aCompanyMember($company);

        // When they request its details with no context header
        $response = $this->viewCompanyDetails($member, $company->getKey());

        // Then the company's details are returned
        $response->assertOk();
        $response->assertJsonPath('data.id', $company->getKey());
    }

    // Happy — every seeded role except manager holds companies.view (s117
    // matrix/seed-authoritative): the DP locks the grant set in step with the
    // seed (owner is exercised by the exact-contract test).
    #[Test]
    #[DataProvider('provideViewingRoles')]
    public function when_role_holds_companies_view_then_company_details_returned(string $roleCode): void
    {
        // Given a member under a companies.view-holding role
        $company = Company::factory()->withSettings()->create();
        $member = $this->aCompanyMember($company, $roleCode);

        // When they request their company's details
        $response = $this->viewCompanyDetails($member, $company->getKey());

        // Then the details are returned
        $response->assertOk();
        $response->assertJsonPath('data.id', $company->getKey());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideViewingRoles(): array
    {
        return [
            'admin' => ['admin'],
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'auditor' => ['auditor'],
            'employee' => ['employee'],
        ];
    }

    // Happy — the exact CompanyResource contract: identity, plan/status/trial
    // and all six settings, nothing more. assertExactJson is the B7 teeth —
    // employee_count, legal_hold and owner_id are locked OUT of the wire shape
    // (declared deferrals, slice-companies B7).
    #[Test]
    public function when_owner_views_company_details_then_complete_details_returned(): void
    {
        // Given the owner of a Pro company mid-trial
        $company = Company::factory()
            ->named('Acme Pvt Ltd')
            ->onPlan(BillingPlan::Pro)
            ->onTrialUntil(now()->addDays(12)->toImmutable())
            ->withSettings()
            ->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they view the company details
        $response = $this->viewCompanyDetails($owner, $company->getKey());

        // Then the complete details are returned — and nothing more
        $response->assertExactJson(['data' => $this->expectedCompanyDetails($company)]);
    }

    // Happy variant — a trial-expired company (Day-31 read-only, plan null by
    // construction) is still viewable: the exact envelope locks the degenerate
    // wire contract, plan and trial expiry rendering as nulls.
    #[Test]
    public function when_owner_views_a_trial_expired_company_then_details_carry_no_plan(): void
    {
        // Given the owner of a trial-expired company
        $company = Company::factory()->trialExpired()->withSettings()->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they view the company details
        $response = $this->viewCompanyDetails($owner, $company->getKey());

        // Then the details render with no plan and no trial expiry
        $response->assertExactJson(['data' => $this->expectedCompanyDetails($company)]);
        $response->assertJsonPath('data.plan', null);
    }
}
