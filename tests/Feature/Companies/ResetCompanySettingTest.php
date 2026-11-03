<?php

namespace Tests\Feature\Companies;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Consent\Events\ConsentRecorded;
use App\Companies\Entities\CompanySettings;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * UC-1-058 alt 1a — DELETE /api/v1/companies/{companyId}/settings/{setting}:
 * a setting with the company's own value goes back to following its country.
 */
class ResetCompanySettingTest extends CompaniesTestCase
{
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
        $this->assertSame(0, LesilyStoredEvent::query()->whereForCompany($company->getKey())->where('event_class', '!=', ConsentRecorded::class)->count());
    }
}
