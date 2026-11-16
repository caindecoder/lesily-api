<?php

namespace Tests\Feature\Foundation\AuditTrail;

use Closure;
use App\Models\User;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Database\Factories\CompanyFactory;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;

class SearchAuditLogsTest extends AuditTrailTestCase
{
    protected Company $proCompany;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 14:30:00');
        $this->proCompany = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $this->owner = $this->aConsentingMember($this->proCompany, 'owner');
    }

    /**
     * @param  Closure(CompanyFactory): CompanyFactory  $standing
     * @param  array<string, string>  $search
     */
    #[Test]
    #[DataProvider('provideStandingsWithoutSearch')]
    public function when_a_plan_without_search_searches_then_search_is_unavailable(Closure $standing, array $search): void
    {
        // Given a company whose plan has no log search, and its owner
        $company = $standing(Company::factory())->create();
        $owner = $this->aConsentingMember($company, 'owner');

        // When the owner searches the company's audit logs
        $response = $this->viewAuditLogs($owner, $company, $search);

        // Then search is refused with the plan that has it
        $this->assertSearchUnavailable($response);
    }

    #[Test]
    public function when_a_role_without_audit_view_searches_then_the_role_is_refused_before_the_plan(): void
    {
        // Given an employee of company A, whose plan has no log search
        $employee = $this->aConsentingMember($this->companyA, 'employee');

        // When the employee searches company A's audit logs
        $response = $this->viewAuditLogs($employee, $this->companyA, ['ip' => '203.0.113.5']);

        // Then the role is refused, not the plan
        $this->assertRoleInsufficient($response);
    }

    /**
     * @return array<string, array{Closure(CompanyFactory): CompanyFactory, array<string, string>}>
     */
    public static function provideStandingsWithoutSearch(): array
    {
        $standings = [
            'free' => fn (CompanyFactory $company) => $company->onPlan(BillingPlan::Free),
            'starter' => fn (CompanyFactory $company) => $company->onPlan(BillingPlan::Starter),
            'an expired trial' => fn (CompanyFactory $company) => $company->trialExpired(),
        ];
        $searches = [
            'by user' => ['user' => '0199a0b0-0000-7000-8000-000000000001'],
            'by event' => ['event' => 'login_failed'],
            'by ip' => ['ip' => '203.0.113.5'],
        ];

        $rows = [];

        foreach ($standings as $standingName => $standing) {
            foreach ($searches as $searchName => $search) {
                $rows["{$standingName} searching {$searchName}"] = [$standing, $search];
            }
        }

        return $rows;
    }

    protected function assertSearchUnavailable(TestResponse $response): void
    {
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::FeatureNotAvailable);
        $response->assertJsonPath('upgrade_to', 'Pro');
        $response->assertJsonPath('upgrade_url', '/settings/billing/upgrade');
    }
}
