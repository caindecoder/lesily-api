<?php

namespace Tests\Feature\Companies;

use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * UC-1-032 View Company Details + the R8 CompanyContextResolver route-param
 * extension (slice-companies Task 5). GET /api/v1/companies/{companyId} —
 * param-first company resolution, membership + permission gated, exact
 * CompanyResource contract (B7 deferrals locked out by shape).
 */
class ViewCompanyDetailsTest extends CompaniesTestCase
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
}
