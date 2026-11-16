<?php

namespace Tests\Feature\Foundation\AuditTrail;

use Closure;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Database\Factories\CompanyFactory;
use PHPUnit\Framework\Attributes\Test;
use Database\Factories\AuditEntryFactory;
use App\Foundation\Billing\Enums\BillingPlan;
use Database\Factories\AccessLogEntryFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Tests\Concerns\AssertsFilterEmptyValueRejection;
use App\Foundation\AccessLog\Entities\AccessLogEntry;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\AuditTrail\UseCases\Commands\AuditLogFilters;

class SearchAuditLogsTest extends AuditTrailTestCase
{
    use AssertsFilterEmptyValueRejection;

    protected Company $proCompany;

    protected User $owner;

    protected User $member;

    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 14:30:00');
        $this->proCompany = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $this->owner = $this->aConsentingMember($this->proCompany, 'owner');
        $this->member = User::factory()->create();
        $this->staff = User::factory()->create();
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

    /**
     * @param  array<string, mixed>  $search
     */
    #[Test]
    #[DataProvider('provideInvalidSearches')]
    public function when_a_search_value_is_invalid_then_it_is_rejected(array $search, string $field, string $message): void
    {
        // Given the owner of a Pro company (setUp)

        // When the owner searches with the invalid value
        $response = $this->searchAuditLogs($search);

        // Then the search is rejected with the reason
        $this->assertValidationRejected($response, $field, $message);
    }

    #[Test]
    #[DataProvider('provideEmptySearchValues')]
    public function when_a_search_value_is_empty_then_it_is_rejected(string $field, string $value): void
    {
        // Given an empty search value

        // When the filters are built from it
        // Then the value is rejected
        $this->assertEmptyFilterValueRejected(AuditLogFilters::class, $field, $value);
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

    #[Test]
    public function when_filtered_by_a_member_then_staff_rows_under_their_id_are_not_listed(): void
    {
        // Given the member's change, and a change written by the same user as Lesily staff
        $change = $this->aChangeBy($this->member)->create();
        AuditEntry::factory()
            ->forCompany($this->proCompany)
            ->byStaff($this->member)
            ->ofAction(AuditAction::RoleChanged, AuditSubjectType::User)
            ->recordedAt(CarbonImmutable::parse('2026-11-11 12:00:00'))
            ->create();

        // When the owner searches by the member
        $response = $this->searchAuditLogs(['user' => $this->member->getKey()]);

        // Then only the member's own change is listed
        $this->assertEntriesListed($response, [$change->getKey()]);
    }

    #[Test]
    public function when_a_member_row_has_no_role_on_record_then_the_user_filter_still_lists_it(): void
    {
        // Given a change by the member written when their membership was gone
        $change = $this->aChangeBy($this->member)->withoutRole()->create();

        // When the owner searches by the member
        $response = $this->searchAuditLogs(['user' => $this->member->getKey()]);

        // Then the change is listed
        $this->assertEntriesListed($response, [$change->getKey()]);
    }

    /**
     * @param  Closure(CompanyFactory): CompanyFactory  $standing
     */
    #[Test]
    #[DataProvider('provideStandingsWithSearch')]
    public function when_a_plan_with_search_searches_then_the_matching_rows_are_listed(Closure $standing): void
    {
        // Given a company whose plan has log search, its owner, and two users' sign-ins
        $company = $standing(Company::factory())->create();
        $owner = $this->aConsentingMember($company, 'owner');
        $signIn = AccessLogEntry::factory()->forCompany($company)->byUser($this->member)->create();
        AccessLogEntry::factory()->forCompany($company)->byUser($this->staff)->create();

        // When the owner searches by the member
        $response = $this->viewAuditLogs($owner, $company, ['user' => $this->member->getKey()]);

        // Then only the member's sign-in is listed
        $this->assertEntriesListed($response, [$signIn->getKey()]);
    }

    #[Test]
    public function when_filtered_by_a_member_then_only_their_sign_ins_and_changes_are_listed(): void
    {
        // Given the member's sign-in and change, and another user's
        $other = User::factory()->create();
        $signIn = $this->aSignInBy($this->member)->seenAt(CarbonImmutable::parse('2026-11-10 09:00:00'))->create();
        $change = $this->aChangeBy($this->member)->recordedAt(CarbonImmutable::parse('2026-11-11 09:00:00'))->create();
        $this->aSignInBy($other)->create();
        $this->aChangeBy($other)->create();

        // When the owner searches by the member
        $response = $this->searchAuditLogs(['user' => $this->member->getKey()]);

        // Then only the member's rows are listed, newest first
        $this->assertEntriesListed($response, [$change->getKey(), $signIn->getKey()]);
    }

    #[Test]
    public function when_filtered_by_lesily_support_then_only_staff_changes_are_listed(): void
    {
        // Given a staff change, and the member's change and sign-in
        $staffChange = $this->aStaffChange()->create();
        $this->aChangeBy($this->member)->create();
        $this->aSignInBy($this->member)->create();

        // When the owner searches by Lesily support
        $response = $this->searchAuditLogs(['user' => 'lesily_support']);

        // Then only the staff change is listed
        $this->assertEntriesListed($response, [$staffChange->getKey()]);
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

    /**
     * @return array<string, array{Closure(CompanyFactory): CompanyFactory}>
     */
    public static function provideStandingsWithSearch(): array
    {
        return [
            'pro' => [fn (CompanyFactory $company) => $company->onPlan(BillingPlan::Pro)],
            'enterprise' => [fn (CompanyFactory $company) => $company->onPlan(BillingPlan::Enterprise)],
            'a live trial' => [fn (CompanyFactory $company) => $company->onTrial()],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidSearches(): array
    {
        return [
            'user neither a member id nor lesily_support' => [['user' => 'someone'], 'user', "The user filter must be a member's id or lesily_support."],
            'array-valued user' => [['user' => ['someone']], 'user', 'The user field must be a string.'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEmptySearchValues(): array
    {
        return [
            'empty user' => ['user', ''],
        ];
    }

    /**
     * @param  array<string, mixed>  $search
     */
    protected function searchAuditLogs(array $search): TestResponse
    {
        return $this->viewAuditLogs($this->owner, $this->proCompany, $search);
    }

    protected function aSignInBy(User $user): AccessLogEntryFactory
    {
        return AccessLogEntry::factory()
            ->forCompany($this->proCompany)
            ->byUser($user)
            ->seenAt(CarbonImmutable::parse('2026-11-10 12:00:00'));
    }

    protected function aChangeBy(User $user): AuditEntryFactory
    {
        return AuditEntry::factory()
            ->forCompany($this->proCompany)
            ->byMember($user, 'admin')
            ->ofAction(AuditAction::PasswordChanged, AuditSubjectType::User)
            ->recordedAt(CarbonImmutable::parse('2026-11-10 12:00:00'));
    }

    protected function aStaffChange(): AuditEntryFactory
    {
        return AuditEntry::factory()
            ->forCompany($this->proCompany)
            ->byStaff($this->staff)
            ->ofAction(AuditAction::RoleChanged, AuditSubjectType::User)
            ->recordedAt(CarbonImmutable::parse('2026-11-10 12:00:00'));
    }

    protected function assertSearchUnavailable(TestResponse $response): void
    {
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::FeatureNotAvailable);
        $response->assertJsonPath('upgrade_to', 'Pro');
        $response->assertJsonPath('upgrade_url', '/settings/billing/upgrade');
    }
}
