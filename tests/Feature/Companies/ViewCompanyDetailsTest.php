<?php

namespace Tests\Feature\Companies;

use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
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
}
