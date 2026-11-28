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
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Tests\Concerns\AssertsFilterEmptyValueRejection;
use App\Foundation\AccessLog\Entities\AccessLogEntry;
use App\Foundation\AccessLog\Enums\AccessEventReason;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\AuditTrail\UseCases\Commands\ListAuditLogsCommand;

class SearchAuditLogsTest extends AuditTrailTestCase
{
    use AssertsFilterEmptyValueRejection;

    protected Company $proCompany;

    protected User $viewer;

    protected User $actor;

    protected User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->proCompany = Company::factory()->onPlan(BillingPlan::Pro)->createOne();
        $this->viewer = $this->aCompanyMember($this->proCompany, 'owner');
        $this->actor = User::factory()->createOne();
        $this->platformAdmin = User::factory()->createOne();
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
        $company = $standing(Company::factory())->createOne();
        $owner = $this->aCompanyMember($company, 'owner');

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
        $this->assertEmptyFilterValueRejected(ListAuditLogsCommand::class, $field, $value);
    }

    #[Test]
    public function when_a_role_without_audit_view_searches_then_the_role_is_refused_before_the_plan(): void
    {
        // Given Contoso's employee; Contoso is on Free, which has no log search
        $this->putOnPlan($this->contosoCompany, BillingPlan::Free);

        // When the employee searches company A's audit logs
        $response = $this->viewAuditLogs($this->member, $this->contosoCompany, ['ip' => '203.0.113.5']);

        // Then the role is refused, not the plan
        $this->assertRoleInsufficient($response, 'audit.view');
    }

    #[Test]
    public function when_a_plan_without_search_sends_an_invalid_search_then_it_is_rejected_before_the_plan(): void
    {
        // Given the owner of company A, which is on Free, a plan with no log search
        $this->putOnPlan($this->contosoCompany, BillingPlan::Free);

        // When the owner searches company A's audit logs with an invalid address
        $response = $this->viewAuditLogs($this->owner, $this->contosoCompany, ['ip' => 'nowhere']);

        // Then the address is rejected before the plan is checked
        $this->assertValidationRejected($response, 'ip', 'The ip field must be a valid IP address.');
    }

    #[Test]
    public function when_filtered_by_a_member_then_staff_rows_under_their_id_are_not_listed(): void
    {
        // Given the member's change, and a change written by the same user as Lesily staff
        $auditEntry = $this->aChangeBy($this->actor)->createOne();
        AuditEntry::factory()
            ->forCompany($this->proCompany)
            ->byStaff($this->actor)
            ->ofAction(AuditAction::RoleChanged, AuditSubjectType::User)
            ->recordedAt(CarbonImmutable::parse('2026-11-11 12:00:00'))
            ->createOne();

        // When the owner searches by the member
        $response = $this->searchAuditLogs(['user' => $this->actor->getKey()]);

        // Then only the member's own change is listed
        $this->assertEntriesListed($response, [$auditEntry->getKey()]);
    }

    #[Test]
    public function when_filtered_by_an_ip_then_staff_rows_from_it_are_not_listed(): void
    {
        // Given the member's sign-in and a staff change, both from the address
        $accessLogEntry = $this->aSignInBy($this->actor)->fromOrigin('203.0.113.5', 'Firefox')->createOne();
        $this->aStaffChange()->fromOrigin('203.0.113.5', 'Firefox')->recordedAt(CarbonImmutable::parse('2026-11-11 12:00:00'))->createOne();

        // When the owner searches by the address
        $response = $this->searchAuditLogs(['ip' => '203.0.113.5']);

        // Then only the member's sign-in is listed
        $this->assertEntriesListed($response, [$accessLogEntry->getKey()]);
    }

    #[Test]
    public function when_a_member_row_has_no_role_on_record_then_the_user_filter_still_lists_it(): void
    {
        // Given a change by the member written when their membership was gone
        $auditEntry = $this->aChangeBy($this->actor)->withoutRole()->createOne();

        // When the owner searches by the member
        $response = $this->searchAuditLogs(['user' => $this->actor->getKey()]);

        // Then the change is listed
        $this->assertEntriesListed($response, [$auditEntry->getKey()]);
    }

    #[Test]
    public function when_another_company_has_matching_rows_then_they_are_not_listed(): void
    {
        // Given the member's sign-in here, and the member's sign-in and change from the same address in company B
        $accessLogEntry = $this->aSignInBy($this->actor)->fromOrigin('203.0.113.5', 'Firefox')->createOne();
        AccessLogEntry::factory()->forCompany($this->otherCompany)->byUser($this->actor)->fromOrigin('203.0.113.5', 'Firefox')->createOne();
        AuditEntry::factory()->forCompany($this->otherCompany)->byMember($this->actor, 'admin')
            ->ofAction(AuditAction::PasswordChanged, AuditSubjectType::User)->fromOrigin('203.0.113.5', 'Firefox')->createOne();

        // When the owner searches by the member and the address
        $response = $this->searchAuditLogs(['user' => $this->actor->getKey(), 'ip' => '203.0.113.5']);

        // Then only this company's sign-in is listed
        $this->assertEntriesListed($response, [$accessLogEntry->getKey()]);
    }

    #[Test]
    public function when_a_matching_row_is_past_the_horizon_then_it_is_not_listed(): void
    {
        // Given the member's sign-ins on the first day Pro keeps and the second before it
        $accessLogEntry = $this->aSignInBy($this->actor)->seenAt(CarbonImmutable::parse('2023-11-15 00:00:00'))->createOne();
        $this->aSignInBy($this->actor)->seenAt(CarbonImmutable::parse('2023-11-14 23:59:59'))->createOne();

        // When the owner searches by the member
        $response = $this->searchAuditLogs(['user' => $this->actor->getKey()]);

        // Then only the sign-in inside the horizon is listed
        $this->assertEntriesListed($response, [$accessLogEntry->getKey()]);
    }

    /**
     * @param  Closure(CompanyFactory): CompanyFactory  $standing
     */
    #[Test]
    #[DataProvider('provideStandingsWithSearch')]
    public function when_a_plan_with_search_searches_then_the_matching_rows_are_listed(Closure $standing): void
    {
        // Given a company whose plan has log search, its owner, and two users' sign-ins
        $company = $standing(Company::factory())->createOne();
        $owner = $this->aCompanyMember($company, 'owner');
        $accessLogEntry = AccessLogEntry::factory()->forCompany($company)->byUser($this->actor)->createOne();
        AccessLogEntry::factory()->forCompany($company)->byUser($this->platformAdmin)->createOne();

        // When the owner searches by the member
        $response = $this->viewAuditLogs($owner, $company, ['user' => $this->actor->getKey()]);

        // Then only the member's sign-in is listed
        $this->assertEntriesListed($response, [$accessLogEntry->getKey()]);
    }

    #[Test]
    public function when_filtered_by_a_member_then_only_their_sign_ins_and_changes_are_listed(): void
    {
        // Given the member's sign-in and change, and another user's
        $otherUser = User::factory()->createOne();
        $accessLogEntry = $this->aSignInBy($this->actor)->seenAt(CarbonImmutable::parse('2026-11-10 09:00:00'))->createOne();
        $auditEntry = $this->aChangeBy($this->actor)->recordedAt(CarbonImmutable::parse('2026-11-11 09:00:00'))->createOne();
        $this->aSignInBy($otherUser)->createOne();
        $this->aChangeBy($otherUser)->createOne();

        // When the owner searches by the member
        $response = $this->searchAuditLogs(['user' => $this->actor->getKey()]);

        // Then only the member's rows are listed, newest first
        $this->assertEntriesListed($response, [$auditEntry->getKey(), $accessLogEntry->getKey()]);
    }

    #[Test]
    public function when_filtered_by_lesily_support_then_only_staff_changes_are_listed(): void
    {
        // Given a staff change, and the member's change and sign-in
        $auditEntry = $this->aStaffChange()->createOne();
        $this->aChangeBy($this->actor)->createOne();
        $this->aSignInBy($this->actor)->createOne();

        // When the owner searches by Lesily support
        $response = $this->searchAuditLogs(['user' => 'lesily_support']);

        // Then only the staff change is listed
        $this->assertEntriesListed($response, [$auditEntry->getKey()]);
    }

    #[Test]
    public function when_filtered_by_a_sign_in_event_then_only_those_access_rows_are_listed(): void
    {
        // Given a failed sign-in, a successful one and a change
        $accessLogEntry = $this->aSignInBy($this->actor)->ofEvent(AccessEvent::LoginFailed, AccessEventReason::WrongPassword)->createOne();
        $this->aSignInBy($this->actor)->createOne();
        $this->aChangeBy($this->actor)->createOne();

        // When the owner searches by the failed sign-in event
        $response = $this->searchAuditLogs(['event' => 'login_failed']);

        // Then only the failed sign-in is listed
        $this->assertEntriesListed($response, [$accessLogEntry->getKey()]);
    }

    #[Test]
    public function when_filtered_by_a_security_action_then_only_those_changes_are_listed(): void
    {
        // Given a 2FA disable, a password change and a sign-in
        $auditEntry = $this->aChangeBy($this->actor)->ofAction(AuditAction::TwoFactorDisabled, AuditSubjectType::User)->createOne();
        $this->aChangeBy($this->actor)->createOne();
        $this->aSignInBy($this->actor)->createOne();

        // When the owner searches by the 2FA-disable action
        $response = $this->searchAuditLogs(['event' => 'two_factor_disabled']);

        // Then only the 2FA disable is listed
        $this->assertEntriesListed($response, [$auditEntry->getKey()]);
    }

    #[Test]
    public function when_filtered_by_an_ip_then_only_rows_from_it_of_both_kinds_are_listed(): void
    {
        // Given a sign-in and a change from the address, and a pair from another
        $accessLogEntry = $this->aSignInBy($this->actor)->fromOrigin('203.0.113.5', 'Firefox')->seenAt(CarbonImmutable::parse('2026-11-10 09:00:00'))->createOne();
        $auditEntry = $this->aChangeBy($this->actor)->fromOrigin('203.0.113.5', 'Firefox')->recordedAt(CarbonImmutable::parse('2026-11-11 09:00:00'))->createOne();
        $this->aSignInBy($this->actor)->fromOrigin('198.51.100.7', 'Safari')->createOne();
        $this->aChangeBy($this->actor)->fromOrigin('198.51.100.7', 'Safari')->createOne();

        // When the owner searches by the address
        $response = $this->searchAuditLogs(['ip' => '203.0.113.5']);

        // Then only the rows from the address are listed, newest first
        $this->assertEntriesListed($response, [$auditEntry->getKey(), $accessLogEntry->getKey()]);
    }

    #[Test]
    public function when_search_filters_combine_with_dates_then_only_rows_matching_all_are_listed_newest_first(): void
    {
        // Given the member's rows from the address inside the dates, inserted out of time order, and rows each missing one condition
        $otherUser = User::factory()->createOne();
        $auditEntry = $this->aChangeBy($this->actor)->fromOrigin('203.0.113.5', 'Firefox')->recordedAt(CarbonImmutable::parse('2026-11-05 09:00:00'))->createOne();
        $newestAccessLogEntry = $this->aSignInBy($this->actor)->fromOrigin('203.0.113.5', 'Firefox')->seenAt(CarbonImmutable::parse('2026-11-14 23:59:59'))->createOne();
        $oldestAccessLogEntry = $this->aSignInBy($this->actor)->fromOrigin('203.0.113.5', 'Firefox')->seenAt(CarbonImmutable::parse('2026-11-01 00:00:00'))->createOne();
        $this->aSignInBy($this->actor)->fromOrigin('203.0.113.5', 'Firefox')->seenAt(CarbonImmutable::parse('2026-11-15 00:00:00'))->createOne();
        $this->aSignInBy($otherUser)->fromOrigin('203.0.113.5', 'Firefox')->seenAt(CarbonImmutable::parse('2026-11-06 09:00:00'))->createOne();
        $this->aChangeBy($this->actor)->fromOrigin('198.51.100.7', 'Safari')->recordedAt(CarbonImmutable::parse('2026-11-07 09:00:00'))->createOne();

        // When the owner searches by the member and the address within the dates
        $response = $this->searchAuditLogs([
            'user' => $this->actor->getKey(),
            'ip' => '203.0.113.5',
            'within' => '2026-11-01,2026-11-14',
        ]);

        // Then only the rows matching all three are listed, newest first
        $this->assertEntriesListed($response, [$newestAccessLogEntry->getKey(), $auditEntry->getKey(), $oldestAccessLogEntry->getKey()]);
    }

    #[Test]
    public function when_the_audit_logs_are_searched_then_no_audit_entry_is_written(): void
    {
        // Given the member's sign-in
        $this->aSignInBy($this->actor)->createOne();

        // When the owner searches by the member
        $response = $this->searchAuditLogs(['user' => $this->actor->getKey()]);

        // Then the search leaves no audit-trail row
        $response->assertOk();
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS);
    }

    /**
     * @return array<string, array{Closure(CompanyFactory): CompanyFactory, array<string, string>}>
     */
    public static function provideStandingsWithoutSearch(): array
    {
        $standings = [
            'free' => fn (CompanyFactory $companyFactory) => $companyFactory->onPlan(BillingPlan::Free),
            'starter' => fn (CompanyFactory $companyFactory) => $companyFactory->onPlan(BillingPlan::Starter),
            'an expired trial' => fn (CompanyFactory $companyFactory) => $companyFactory->trialExpired(),
        ];
        $searches = [
            'by user' => ['user' => '0199a0b0-0000-7000-8000-000000000001'],
            'by lesily support' => ['user' => 'lesily_support'],
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
            'pro' => [fn (CompanyFactory $companyFactory) => $companyFactory->onPlan(BillingPlan::Pro)],
            'enterprise' => [fn (CompanyFactory $companyFactory) => $companyFactory->onPlan(BillingPlan::Enterprise)],
            'a live trial' => [fn (CompanyFactory $companyFactory) => $companyFactory->onTrial()],
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
            'event that is no logged code' => [['event' => 'sideways'], 'event', 'The selected event is invalid.'],
            'event the audit logs never show' => [['event' => 'user_invited'], 'event', 'The selected event is invalid.'],
            'array-valued event' => [['event' => ['login_failed']], 'event', 'The event field must be a string.'],
            'ip that is only part of an address' => [['ip' => '203.0.113'], 'ip', 'The ip field must be a valid IP address.'],
            'array-valued ip' => [['ip' => ['203.0.113.5']], 'ip', 'The ip field must be a string.'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEmptySearchValues(): array
    {
        return [
            'empty user' => ['user', ''],
            'empty event' => ['event', ''],
            'empty ip' => ['ip', ''],
        ];
    }

    /**
     * @param  array<string, mixed>  $search
     */
    protected function searchAuditLogs(array $search): TestResponse
    {
        return $this->viewAuditLogs($this->viewer, $this->proCompany, $search);
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
            ->byStaff($this->platformAdmin)
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
