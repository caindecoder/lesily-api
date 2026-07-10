<?php

namespace Tests\Feature\Companies;

use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // Sad — the crux immutability guard: br_number, epf_number, country and the
    // other non-editable columns are refused by the command whitelist before any
    // write. Any key outside the editable set — including an unknown one — is a 422.
    #[Test]
    #[DataProvider('provideImmutableFields')]
    public function when_immutable_field_submitted_then_update_rejected(array $payload, string $field): void
    {
        // Given the owner of a company
        $company = Company::factory()->withSettings()->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they submit an update touching a non-editable field
        $response = $this->updateCompanyDetails($owner, $company->getKey(), $payload);

        // Then the update is rejected with an error naming that field
        $this->assertValidationFailed($response, $field);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideImmutableFields(): array
    {
        return [
            'br_number' => [['br_number' => 'PV-99999999'], 'br_number'],
            'epf_number' => [['epf_number' => 'EPF-9999999'], 'epf_number'],
            'country' => [['country' => 'IN'], 'country'],
            'current_billing_plan' => [['current_billing_plan' => 'pro'], 'current_billing_plan'],
            'owner_id' => [['owner_id' => '0193abcd-0000-7000-8000-000000000000'], 'owner_id'],
            'unknown key' => [['nonsense' => 'x'], 'nonsense'],
        ];
    }

    // Sad — an editable field carrying an out-of-range value is refused: the name
    // has a length cap, the timezone must be a real IANA zone, and the fiscal
    // month / working days / work hours sit within their domain bounds.
    #[Test]
    #[DataProvider('provideInvalidEditableFields')]
    public function when_editable_field_is_invalid_then_update_rejected(array $payload, string $field): void
    {
        // Given the owner of a company
        $company = Company::factory()->withSettings()->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they submit an out-of-range value for an editable field
        $response = $this->updateCompanyDetails($owner, $company->getKey(), $payload);

        // Then the update is rejected with an error naming that field
        $this->assertValidationFailed($response, $field);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidEditableFields(): array
    {
        return [
            'name too long' => [['name' => str_repeat('a', 256)], 'name'],
            'timezone not real' => [['timezone' => 'Mars/Phobos'], 'timezone'],
            'fiscal month below one' => [['fiscalYearStartMonth' => 0], 'fiscalYearStartMonth'],
            'fiscal month above twelve' => [['fiscalYearStartMonth' => 13], 'fiscalYearStartMonth'],
            'working days below one' => [['workingDaysPerWeek' => 0], 'workingDaysPerWeek'],
            'working days above seven' => [['workingDaysPerWeek' => 8], 'workingDaysPerWeek'],
            'work hours below zero' => [['defaultWorkHoursPerDay' => -1], 'defaultWorkHoursPerDay'],
            'work hours above twenty four' => [['defaultWorkHoursPerDay' => 25], 'defaultWorkHoursPerDay'],
        ];
    }

    // Sad — the minimal guarantee (UC-1-033): a rejected update changes nothing.
    // A blank company name is refused and Contoso keeps its name, its settings and
    // an empty event stream (no partial write escaped the guard).
    #[Test]
    public function when_editable_field_is_invalid_then_company_is_unchanged(): void
    {
        // Given the owner of a company named Contoso
        $company = Company::factory()->withSettings()->named('Contoso')->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they submit a blank company name
        $response = $this->updateCompanyDetails($owner, $company->getKey(), ['name' => '']);

        // Then the update is rejected and the company is untouched
        $this->assertValidationFailed($response, 'name');
        $this->assertCompanyUnchanged($company, 'Contoso');
    }
}
