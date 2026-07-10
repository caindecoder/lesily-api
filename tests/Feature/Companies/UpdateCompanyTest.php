<?php

namespace Tests\Feature\Companies;

use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * UC-1-033 Update Company Details (slice-companies Task 6). PUT /api/v1/companies/{companyId}
 * — Owner/Admin edit name + settings through the event-sourced Company aggregate
 * (two events: CompanyDetailsUpdated + CompanySettingsUpdated). Immutable fields
 * (br_number/epf_number/country) are refused by the whitelist guard.
 */
class UpdateCompanyTest extends CompaniesTestCase
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

    // Sad — the update is auth-gated: a guest is refused before any company
    // resolution and learns nothing about the company id.
    #[Test]
    public function when_unauthenticated_then_company_update_rejected(): void
    {
        // Given an existing company and no authenticated session
        $company = Company::factory()->withSettings()->create();

        // When a guest submits an update
        $response = $this->updateCompanyDetailsAsGuest($company->getKey(), ['name' => 'New Name']);

        // Then the request is rejected as unauthenticated
        $this->assertUnauthenticated($response);
    }
}
