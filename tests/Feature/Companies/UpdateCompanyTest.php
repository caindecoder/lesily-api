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

    // Sad — R8 teeth on the write route: the URL names a company the member does
    // not belong to. auth.company resolves the URL company (param-first) and the
    // membership check runs against it, so a foreign company is denied.
    #[Test]
    public function when_member_updates_another_companys_details_then_company_access_denied(): void
    {
        // Given a member of Company A holding a foreign Company B's id
        $companyA = Company::factory()->withSettings()->create();
        $companyB = Company::factory()->withSettings()->create();
        $member = $this->aCompanyMember($companyA, 'owner');

        // When they submit an update against Company B
        $response = $this->updateCompanyDetails($member, $companyB->getKey(), ['name' => 'New Name']);

        // Then company access is denied
        $this->assertCompanyAccessDenied($response);
    }

    // Sad — an unknown URL company id is a plain 404 (the shared resolver's
    // findOrFail on the param): existence is not leaked, mirroring the view route.
    #[Test]
    public function when_requested_company_is_unknown_then_resource_not_found(): void
    {
        // Given a member of a real company holding an unknown company id
        $company = Company::factory()->withSettings()->create();
        $member = $this->aCompanyMember($company, 'owner');

        // When they submit an update for an unknown company id
        $response = $this->updateCompanyDetails($member, '0193abcd-0000-7000-8000-000000000000', ['name' => 'New Name']);

        // Then the resource is not found
        $this->assertResourceNotFound($response);
    }

    // Sad — the update is owner/admin only (companies.update seeded to those two):
    // every other seeded role is refused with the missing grant named.
    #[Test]
    #[DataProvider('provideNonUpdatingRoles')]
    public function when_role_lacks_companies_update_then_update_forbidden(string $roleCode): void
    {
        // Given a member under a role without companies.update
        $company = Company::factory()->withSettings()->create();
        $member = $this->aCompanyMember($company, $roleCode);

        // When they submit an update
        $response = $this->updateCompanyDetails($member, $company->getKey(), ['name' => 'New Name']);

        // Then the update is refused for the missing companies.update grant
        $this->assertRoleInsufficient($response, 'companies.update');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideNonUpdatingRoles(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'auditor' => ['auditor'],
            'employee' => ['employee'],
            'manager' => ['manager'],
        ];
    }

    // Edge — a name-only update records the details event and nothing else:
    // the settings event fires only when a settings field is present (conditional
    // emission), so the settings stream stays silent.
    #[Test]
    public function when_only_name_submitted_then_only_the_details_event_is_recorded(): void
    {
        // Given the owner of a company named Old Co
        $company = Company::factory()->withSettings()->named('Old Co')->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they submit only a new name
        $response = $this->updateCompanyDetails($owner, $company->getKey(), ['name' => 'New Co']);

        // Then only the details event is recorded and the name is updated
        $response->assertOk();
        $this->assertOnlyDetailsEventRecorded($company, 'New Co');
    }
}
