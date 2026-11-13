<?php

namespace Tests\Feature\Companies;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Consent\Events\ConsentRecorded;
use App\Companies\Entities\CompanySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * UC-1-058 alt 1a — DELETE /api/v1/companies/{companyId}/settings/{setting}:
 * a setting with the company's own value goes back to following its country.
 */
class ResetCompanySettingTest extends CompaniesTestCase
{
    /**
     * The own values CompanySettingsFactory::ownValues() gives a company.
     */
    protected const array OWN_VALUES = [
        'timezone' => 'Asia/Singapore',
        'fiscal_year_start_month' => 7,
        'working_days_per_week' => 6,
        'default_work_hours_per_day' => 7.5,
    ];

    protected const array SRI_LANKA_DEFAULTS = [
        'timezone' => 'Asia/Colombo',
        'fiscal_year_start_month' => 4,
        'working_days_per_week' => 5,
        'default_work_hours_per_day' => 8,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();

        config(['app.debug' => false]);
    }

    #[Test]
    public function when_member_without_update_permission_resets_a_setting_then_refused_and_own_value_kept(): void
    {
        // Given an HR officer of a company with its own settings
        $company = Company::factory()->withOwnSettings()->create();
        $hrOfficer = $this->aCompanyMember($company, 'hr_officer');

        // When they reset the company's timezone
        $response = $this->resetCompanySetting($hrOfficer, $company->getKey(), 'timezone');

        // Then they are refused and the company keeps its own value
        $this->assertRoleInsufficient($response, 'companies.update');
        $this->assertOwnValueKept($company);
    }

    #[Test]
    public function when_member_of_another_company_resets_a_setting_then_refused_and_own_value_kept(): void
    {
        // Given the owner of company A and company B with its own settings
        $companyA = Company::factory()->withOwnSettings()->create();
        $companyB = Company::factory()->withOwnSettings()->create();
        $ownerOfA = $this->aCompanyMember($companyA, 'owner');

        // When they reset company B's timezone
        $response = $this->resetCompanySetting($ownerOfA, $companyB->getKey(), 'timezone');

        // Then company access is denied and company B keeps its own value
        $this->assertCompanyAccessDenied($response);
        $this->assertOwnValueKept($companyB);
    }

    #[Test]
    #[DataProvider('provideSettingsThatCannotBeReset')]
    public function when_setting_cannot_be_reset_then_not_found_and_nothing_recorded(string $setting): void
    {
        // Given the owner of a company with its own settings
        $company = Company::factory()->withOwnSettings()->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they reset a setting that cannot be reset
        $response = $this->resetCompanySetting($owner, $company->getKey(), $setting);

        // Then it is not found and nothing is recorded
        $this->assertResourceNotFound($response);
        $this->assertNothingRecorded($company);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideSettingsThatCannotBeReset(): array
    {
        return [
            'unknown setting' => ['overtime_rate'],
            'currency' => ['currency'],
            'default language' => ['locale_default'],
            'camelCase spelling' => ['workingDaysPerWeek'],
        ];
    }

    #[Test]
    public function when_setting_already_follows_the_country_then_nothing_is_recorded(): void
    {
        // Given the owner of a company that follows its country on every setting
        $company = Company::factory()->withSettings()->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they reset its working days
        $response = $this->resetCompanySetting($owner, $company->getKey(), 'working_days_per_week');

        // Then the reset answers, nothing is recorded and nothing is audited
        $response->assertNoContent();
        $this->assertNothingRecorded($company);
        $this->assertNothingAudited();
        $this->assertSettingFollowsTheCountry($company, 'working_days_per_week');
    }

    #[Test]
    public function when_a_company_resets_a_setting_then_another_company_keeps_its_own_value(): void
    {
        // Given two companies with their own settings and the owner of company A
        $companyA = Company::factory()->withOwnSettings()->create();
        $companyB = Company::factory()->withOwnSettings()->create();
        $ownerOfA = $this->aCompanyMember($companyA, 'owner');

        // When they reset company A's timezone
        $response = $this->resetCompanySetting($ownerOfA, $companyA->getKey(), 'timezone');

        // Then company B keeps its own value and records nothing
        $response->assertNoContent();
        $this->assertOwnValueKept($companyB);
    }

    #[Test]
    #[DataProvider('provideResettableSettings')]
    public function when_a_setting_with_an_own_value_is_reset_then_it_follows_the_country(string $setting, string $role): void
    {
        // Given a member allowed to update a company with its own settings
        $company = Company::factory()->withOwnSettings()->create();
        $member = $this->aCompanyMember($company, $role);

        // When they reset one of its settings from a device
        $this->fromADevice();
        $response = $this->resetCompanySetting($member, $company->getKey(), $setting);

        // Then the setting follows the country, the reset is recorded and audited own value -> country default, and the other own values stay
        $response->assertNoContent();
        $this->assertSettingFollowsTheCountry($company, $setting);
        $this->assertResetRecorded($company, $setting, $member->getKey());
        $this->assertCompanyChangeAudited($company, AuditAction::CompanySettingReset, [$setting => ['from' => self::OWN_VALUES[$setting], 'to' => self::SRI_LANKA_DEFAULTS[$setting]]]);
        $this->assertEveryRowCameFrom($response, $member, $role);
        $this->assertOtherOwnValuesKept($company, $setting);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideResettableSettings(): array
    {
        return [
            'owner resets the timezone' => ['timezone', 'owner'],
            'admin resets the fiscal year start' => ['fiscal_year_start_month', 'admin'],
            'owner resets the working days' => ['working_days_per_week', 'owner'],
            'admin resets the work hours' => ['default_work_hours_per_day', 'admin'],
        ];
    }

    protected function resetCompanySetting(User $user, string $companyId, string $setting): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->deleteJson('/api/v1/companies/'.$companyId.'/settings/'.$setting);
    }

    protected function assertOwnValueKept(Company $company): void
    {
        $settings = CompanySettings::query()->where('company_id', $company->getKey())->sole();

        $this->assertSame('Asia/Singapore', $settings->timezone);
        $this->assertSame(7, $settings->fiscal_year_start_month);
        $this->assertSame(6, $settings->working_days_per_week);
        $this->assertSame(7.5, $settings->default_work_hours_per_day);
        $this->assertNothingRecorded($company);
    }

    protected function assertOtherOwnValuesKept(Company $company, string $resetSetting): void
    {
        $settings = CompanySettings::query()->where('company_id', $company->getKey())->sole();
        $otherOwnValues = array_diff_key(self::OWN_VALUES, [$resetSetting => true]);

        $this->assertCount(3, $otherOwnValues);
        foreach ($otherOwnValues as $setting => $ownValue) {
            $this->assertSame($ownValue, $settings->getAttribute($setting));
        }
    }

    protected function assertNothingRecorded(Company $company): void
    {
        $this->assertSame(0, LesilyStoredEvent::query()->whereForCompany($company->getKey())->where('event_class', '!=', ConsentRecorded::class)->count());
    }
}
