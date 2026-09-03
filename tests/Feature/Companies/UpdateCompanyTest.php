<?php

namespace Tests\Feature\Companies;

use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * UC-1-033 Update Company Details (slice-companies Task 6). PUT /api/v1/companies/{companyId}
 * — Owner/Admin edit name + settings through the event-sourced Company aggregate
 * (two events: CompanyDetailsUpdated + CompanySettingsUpdated). Immutable fields
 * (br_number/epf_number/country) are refused by the whitelist guard.
 */
class UpdateCompanyTest extends CompaniesTestCase
{
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
    public function when_immutable_field_submitted_then_update_rejected(array $payload, string $field, string $message): void
    {
        // Given the owner of a company
        $company = Company::factory()->withSettings()->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they submit an update touching a non-editable field
        $response = $this->updateCompanyDetails($owner, $company->getKey(), $payload);

        // Then the update is rejected with an error naming that field
        $this->assertValidationRejected($response, $field, $message);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideImmutableFields(): array
    {
        return [
            'br_number' => [['br_number' => 'PV-99999999'], 'br_number', 'This field cannot be updated.'],
            'epf_number' => [['epf_number' => 'EPF-9999999'], 'epf_number', 'This field cannot be updated.'],
            'country' => [['country' => 'IN'], 'country', 'This field cannot be updated.'],
            'current_billing_plan' => [['current_billing_plan' => 'pro'], 'current_billing_plan', 'This field cannot be updated.'],
            'owner_id' => [['owner_id' => '0193abcd-0000-7000-8000-000000000000'], 'owner_id', 'This field cannot be updated.'],
            'unknown key' => [['nonsense' => 'x'], 'nonsense', 'This field cannot be updated.'],
        ];
    }

    // Sad — an editable field carrying an out-of-range value is refused: the name
    // has a length cap, the timezone must be a real IANA zone, and the fiscal
    // month / working days / work hours sit within their domain bounds.
    #[Test]
    #[DataProvider('provideInvalidEditableFields')]
    public function when_editable_field_is_invalid_then_update_rejected(array $payload, string $field, string $message): void
    {
        // Given the owner of a company
        $company = Company::factory()->withSettings()->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they submit an out-of-range value for an editable field
        $response = $this->updateCompanyDetails($owner, $company->getKey(), $payload);

        // Then the update is rejected with an error naming that field
        $this->assertValidationRejected($response, $field, $message);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidEditableFields(): array
    {
        return [
            'name too long' => [['name' => str_repeat('a', 256)], 'name', 'The name field must not be greater than 255 characters.'],
            'timezone not real' => [['timezone' => 'Mars/Phobos'], 'timezone', 'The timezone field must be a valid timezone.'],
            'fiscal month below one' => [['fiscalYearStartMonth' => 0], 'fiscalYearStartMonth', 'The fiscal year start month field must be between 1 and 12.'],
            'fiscal month above twelve' => [['fiscalYearStartMonth' => 13], 'fiscalYearStartMonth', 'The fiscal year start month field must be between 1 and 12.'],
            'working days below one' => [['workingDaysPerWeek' => 0], 'workingDaysPerWeek', 'The working days per week field must be between 1 and 7.'],
            'working days above seven' => [['workingDaysPerWeek' => 8], 'workingDaysPerWeek', 'The working days per week field must be between 1 and 7.'],
            'work hours below zero' => [['defaultWorkHoursPerDay' => -1], 'defaultWorkHoursPerDay', 'The default work hours per day field must be between 0 and 24.'],
            'work hours above twenty four' => [['defaultWorkHoursPerDay' => 25], 'defaultWorkHoursPerDay', 'The default work hours per day field must be between 0 and 24.'],
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
        $this->assertValidationRejected($response, 'name', 'The name field is required.');
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

    // Edge — a settings-only update records the settings event and nothing else:
    // the details event fires only when a name is present (conditional emission).
    #[Test]
    public function when_only_settings_submitted_then_only_the_settings_event_is_recorded(): void
    {
        // Given the owner of a company whose timezone defaults to Asia/Colombo
        $company = Company::factory()->withSettings()->named('Steady Co')->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they submit only a new timezone
        $response = $this->updateCompanyDetails($owner, $company->getKey(), ['timezone' => 'Asia/Singapore']);

        // Then only the settings event is recorded and the timezone is updated
        $response->assertOk();
        $this->assertOnlySettingsEventRecorded($company, 'Asia/Singapore');
    }

    // Edge — the aggregate is guardless: re-submitting the current values still
    // records both events (no diff-check — the audit trail reflects the action,
    // changeRole same-role precedent).
    #[Test]
    public function when_submitted_values_match_current_then_update_is_still_recorded(): void
    {
        // Given the owner of a company with the default LK settings
        $company = Company::factory()->withSettings()->named('Same Co')->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they re-submit the current name and timezone unchanged
        $response = $this->updateCompanyDetails($owner, $company->getKey(), [
            'name' => 'Same Co',
            'timezone' => 'Asia/Colombo',
        ]);

        // Then both events are still recorded
        $response->assertOk();
        $this->assertUpdateEventsRecorded($company);
    }

    // Edge — an empty payload is a no-op: nothing is submitted, so no event fires
    // and the company is untouched (Chamil's s122 ruling — 200, not a 422).
    #[Test]
    public function when_no_fields_submitted_then_update_is_a_no_op(): void
    {
        // Given the owner of a company named Idle Co
        $company = Company::factory()->withSettings()->named('Idle Co')->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they submit an empty update
        $response = $this->updateCompanyDetails($owner, $company->getKey(), []);

        // Then the request succeeds and nothing changed
        $response->assertOk();
        $this->assertCompanyUnchanged($company, 'Idle Co');
    }

    // Edge — a single-setting update writes only the submitted field and preserves
    // the rest (the projector filters to non-null keys): the fiscal year moves to
    // its upper bound while timezone, working days and work hours keep their values.
    #[Test]
    public function when_one_setting_is_updated_then_the_others_are_preserved(): void
    {
        // Given the owner of a company on the LK defaults
        $company = Company::factory()->withSettings()->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they change only the fiscal year start month (to the upper bound)
        $response = $this->updateCompanyDetails($owner, $company->getKey(), ['fiscalYearStartMonth' => 12]);

        // Then the fiscal month changes and every other setting is preserved
        $response->assertOk();
        $this->assertDatabaseHas('company_settings', [
            'company_id' => $company->getKey(),
            'fiscal_year_start_month' => 12,
            'timezone' => 'Asia/Colombo',
            'working_days_per_week' => 5,
            'default_work_hours_per_day' => 8.0,
        ]);
    }

    // Edge — zero is a valid work-hours value (0-24), distinct from "absent": the
    // strict null check (not a truthy one) records the settings event and writes
    // 0.0, rather than treating zero as no change.
    #[Test]
    public function when_work_hours_are_set_to_zero_then_the_update_is_recorded(): void
    {
        // Given the owner of a company on the LK defaults (8 work hours)
        $company = Company::factory()->withSettings()->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they set the default work hours to zero
        $response = $this->updateCompanyDetails($owner, $company->getKey(), ['defaultWorkHoursPerDay' => 0]);

        // Then the settings event fires (timezone preserved) and the read model reads zero
        $response->assertOk();
        $this->assertOnlySettingsEventRecorded($company, 'Asia/Colombo');
        $this->assertDatabaseHas('company_settings', [
            'company_id' => $company->getKey(),
            'default_work_hours_per_day' => 0,
        ]);
    }

    // Happy — the update is permitted for owner and admin (companies.update):
    // both roles land a 200 with the new name (the denied side is covered above).
    #[Test]
    #[DataProvider('provideUpdatingRoles')]
    public function when_authorized_role_updates_company_then_update_succeeds(string $roleCode): void
    {
        // Given a member under a role that holds companies.update
        $company = Company::factory()->withSettings()->named('Before')->create();
        $member = $this->aCompanyMember($company, $roleCode);

        // When they update the company name
        $response = $this->updateCompanyDetails($member, $company->getKey(), ['name' => 'After']);

        // Then the update succeeds and the new name is returned
        $response->assertOk();
        $response->assertJsonPath('data.name', 'After');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUpdatingRoles(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
        ];
    }

    // Happy — the owner edits the name and all four settings in one request: the
    // updated resource is returned, both read models reflect the new values, and
    // both edits are stored the event-sourced way (amendment R — updatedBy /
    // updatedAt / the changed fields), locking the exact wire shape (B7 teeth).
    #[Test]
    public function when_owner_updates_name_and_settings_then_company_is_updated(): void
    {
        // Given the owner of a Pro company mid-trial with the default LK settings
        $company = Company::factory()
            ->named('Contoso')
            ->onPlan(BillingPlan::Pro)
            ->onTrialUntil(now()->addDays(12)->toImmutable())
            ->withSettings()
            ->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they update the name and every editable setting
        $response = $this->updateCompanyDetails($owner, $company->getKey(), [
            'name' => 'Contoso (Pvt) Ltd',
            'timezone' => 'Asia/Singapore',
            'fiscalYearStartMonth' => 4,
            'workingDaysPerWeek' => 6,
            'defaultWorkHoursPerDay' => 7.5,
        ]);

        // Then the updated resource is returned and both models + streams reflect it
        $response->assertOk();
        $response->assertExactJson(['data' => $this->expectedCompanyDetails($company)]);
        $this->assertDatabaseHas('companies', [
            'id' => $company->getKey(),
            'name' => 'Contoso (Pvt) Ltd',
        ]);
        $this->assertDatabaseHas('company_settings', [
            'company_id' => $company->getKey(),
            'timezone' => 'Asia/Singapore',
            'fiscal_year_start_month' => 4,
            'working_days_per_week' => 6,
            'default_work_hours_per_day' => 7.5,
        ]);
        $this->assertCompanyUpdateWasEventSourced($company, $owner->getKey());
    }
}
