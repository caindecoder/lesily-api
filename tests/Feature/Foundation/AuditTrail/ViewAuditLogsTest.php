<?php

namespace Tests\Feature\Foundation\AuditTrail;

use Closure;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Database\Factories\CompanyFactory;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Tests\Concerns\AssertsFilterEmptyValueRejection;
use App\Foundation\AccessLog\Entities\AccessLogEntry;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\AuditTrail\UseCases\Commands\AuditLogFilters;

class ViewAuditLogsTest extends AuditTrailTestCase
{
    use AssertsFilterEmptyValueRejection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 14:30:00');
    }

    #[Test]
    public function when_a_guest_views_the_audit_logs_then_they_are_asked_to_sign_in(): void
    {
        // Given company A
        // When a caller with no token views company A's audit logs
        $response = $this->viewAuditLogs(null, $this->companyA);

        // Then they are refused as unauthenticated and no row is shown
        $this->assertUnauthenticated($response);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutTheAuditPermission')]
    public function when_a_role_without_audit_view_views_the_audit_logs_then_it_is_refused(string $roleCode): void
    {
        // Given a member of company A whose role cannot view the audit logs
        $member = $this->aConsentingMember($this->companyA, $roleCode);

        // When the member views company A's audit logs
        $response = $this->viewAuditLogs($member, $this->companyA);

        // Then the role is refused and no row is shown
        $this->assertRoleInsufficient($response);
        $response->assertJsonMissingPath('data');
    }

    #[Test]
    public function when_a_member_views_another_company_audit_logs_then_it_is_refused(): void
    {
        // Given the owner of company B
        $outsider = $this->aConsentingMember($this->companyB, 'owner');

        // When B's owner views company A's audit logs
        $response = $this->viewAuditLogs($outsider, $this->companyA);

        // Then access to company A is denied and no row is shown
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::CompanyAccessDenied);
        $response->assertJsonMissingPath('data');
    }

    #[Test]
    public function when_consent_is_owed_then_the_audit_logs_are_withheld(): void
    {
        // Given A's owner accepted the policies before a new privacy policy version
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        config(['lesily.policies.privacy' => 'v1.1']);

        // When the owner views company A's audit logs
        $response = $this->viewAuditLogs($owner, $this->companyA);

        // Then the audit logs are refused until they accept, and no row is shown
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::ConsentRequired);
        $response->assertJsonMissingPath('data');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideInvalidAuditLogQueries')]
    public function when_the_query_is_invalid_then_it_is_rejected(array $query, string $field, string $message): void
    {
        // Given company A's owner
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit logs with an invalid query
        $response = $this->viewAuditLogs($owner, $this->companyA, $query);

        // Then the query is refused with the reason
        $this->assertValidationRejected($response, $field, $message);
    }

    #[Test]
    #[DataProvider('provideEmptyFilterValues')]
    public function when_a_filter_value_is_empty_then_it_is_rejected(string $field, string $value): void
    {
        // Given an empty or blank value for one of the audit logs' query keys
        // When the audit logs filter is built from it
        // Then the value is rejected
        $this->assertEmptyFilterValueRejected(AuditLogFilters::class, $field, $value);
    }

    #[Test]
    #[DataProvider('provideWriteVerbs')]
    public function when_a_write_verb_is_used_then_it_is_not_allowed(string $verb): void
    {
        // Given company A's owner
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner sends a write verb to company A's audit logs
        $response = $this->sendToAuditTrail($verb, $owner, '/api/v1/audit-logs');

        // Then no route answers it
        $this->assertRefusedWith($response, Response::HTTP_METHOD_NOT_ALLOWED, ApiErrorCode::HttpError);
    }

    #[Test]
    public function when_the_company_has_no_logs_then_an_empty_list_with_its_start_is_shown(): void
    {
        // Given company A, on the Free plan, with no sign-in or security record
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit logs
        $response = $this->viewAuditLogs($owner, $this->companyA);

        // Then nothing is listed, and the list starts one year back
        $this->assertNothingListed($response);
        $this->assertListStartsOn($response, '2025-11-15');
    }

    #[Test]
    public function when_another_company_has_logs_then_only_this_company_logs_are_shown(): void
    {
        // Given company A and company B each have a sign-in and a password change
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        $signIn = $this->anAccessRowOfCompanyAAt('2026-11-15 10:00:00');
        $passwordChange = $this->aSecurityEntryOfCompanyAAt('2026-11-15 09:00:00');
        AccessLogEntry::factory()->forCompany($this->companyB)->create();
        AuditEntry::factory()->forCompany($this->companyB)->ofAction(AuditAction::PasswordChanged, AuditSubjectType::User)->create();

        // When the owner views company A's audit logs
        $response = $this->viewAuditLogs($owner, $this->companyA);

        // Then only company A's two rows are listed, newest first
        $this->assertEntriesListed($response, [$signIn->getKey(), $passwordChange->getKey()]);
    }

    #[Test]
    public function when_a_platform_row_exists_then_it_is_not_shown(): void
    {
        // Given a member of company A refused at another company's door (a row with no company), and a sign-in in A
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        AccessLogEntry::factory()->byUser($owner)->ofEvent(AccessEvent::Forbidden)->create();
        $signIn = $this->anAccessRowOfCompanyAAt('2026-11-15 10:00:00');

        // When the owner views company A's audit logs
        $response = $this->viewAuditLogs($owner, $this->companyA);

        // Then only the company's own row is listed
        $this->assertEntriesListed($response, [$signIn->getKey()]);
    }

    #[Test]
    public function when_audit_rows_outside_the_security_set_exist_then_they_are_not_listed(): void
    {
        // Given company A recorded an invitation, a data request and a password change
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        $this->anEntryOfCompanyAFor(AuditAction::UserInvited, AuditSubjectType::Invitation);
        $this->anEntryOfCompanyAFor(AuditAction::DsarRequested, AuditSubjectType::DsarRequest);
        $passwordChange = $this->aSecurityEntryOfCompanyAAt('2026-11-15 09:00:00');

        // When the owner views company A's audit logs
        $response = $this->viewAuditLogs($owner, $this->companyA);

        // Then only the password change is listed
        $this->assertEntriesListed($response, [$passwordChange->getKey()]);
    }

    #[Test]
    public function when_a_row_is_just_inside_the_horizon_then_it_is_shown_and_one_just_outside_is_not(): void
    {
        // Given company A on the Free plan, with a sign-in and a password change at the first moment it keeps,
        // and one of each a second earlier
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        $signInInside = $this->anAccessRowOfCompanyAAt('2025-11-15 00:00:00');
        $passwordChangeInside = $this->aSecurityEntryOfCompanyAAt('2025-11-15 00:00:00');
        $this->anAccessRowOfCompanyAAt('2025-11-14 23:59:59');
        $this->aSecurityEntryOfCompanyAAt('2025-11-14 23:59:59');

        // When the owner views company A's audit logs
        $response = $this->viewAuditLogs($owner, $this->companyA);

        // Then only the two rows inside the year are listed
        $this->assertEntriesListed($response, $this->newestIdFirst([$signInInside->getKey(), $passwordChangeInside->getKey()]));
    }

    #[Test]
    public function when_the_horizon_falls_on_a_leap_day_then_it_starts_on_the_last_day_of_february(): void
    {
        // Given company A on the Free plan, viewed on 29 February 2028
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        $this->travelTo('2028-02-29 10:00:00');

        // When the owner views company A's audit logs
        $response = $this->viewAuditLogs($owner, $this->companyA);

        // Then the year it keeps starts on 28 February 2027, never in March
        $response->assertOk();
        $this->assertListStartsOn($response, '2027-02-28');
    }

    #[Test]
    public function when_a_folded_refusal_is_listed_then_its_count_and_first_time_are_shown(): void
    {
        // Given a refusal first seen at 14:02 and repeated six times until 14:06, and a password change at 14:04
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        $refusals = AccessLogEntry::factory()
            ->forCompany($this->companyA)
            ->ofEvent(AccessEvent::Forbidden)
            ->seenAt(CarbonImmutable::parse('2026-11-15 14:06:00'))
            ->foldedSince(CarbonImmutable::parse('2026-11-15 14:02:00'), 6)
            ->create();
        $passwordChange = $this->aSecurityEntryOfCompanyAAt('2026-11-15 14:04:00');

        // When the owner views company A's audit logs
        $response = $this->viewAuditLogs($owner, $this->companyA);

        // Then the refusals come first, by their last time, with their count and first time
        $this->assertEntriesListed($response, [$refusals->getKey(), $passwordChange->getKey()]);
        $this->assertRowShows($response, 0, [
            'time' => '2026-11-15T14:06:00.000000Z',
            'first_seen_at' => '2026-11-15T14:02:00.000000Z',
            'occurrences' => 6,
        ]);
    }

    #[Test]
    #[DataProvider('provideUnknownUsers')]
    public function when_the_user_of_a_row_is_unknown_then_no_user_name_is_shown(?string $userId): void
    {
        // Given a refusal in company A whose user is unknown to the accounts
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        AccessLogEntry::factory()->forCompany($this->companyA)->ofEvent(AccessEvent::NotFound)->forUserId($userId)->create();

        // When the owner views company A's audit logs
        $response = $this->viewAuditLogs($owner, $this->companyA);

        // Then the row keeps the stored user id and shows no name
        $this->assertRowShows($response, 0, ['user_id' => $userId, 'user_name' => null]);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function provideUnknownUsers(): array
    {
        return [
            'no user' => [null],
            'an id with no account' => ['01970000-0000-7000-8000-000000000001'],
        ];
    }

    /**
     * @param  Closure(CompanyFactory): CompanyFactory  $standing
     */
    #[Test]
    #[DataProvider('providePlansAndTheirHorizons')]
    public function when_the_plan_sets_the_horizon_then_the_list_starts_there(Closure $standing, string $firstDay): void
    {
        // Given a company on the plan, and its owner
        $company = $standing(Company::factory())->create();
        $owner = $this->aConsentingMember($company, 'owner');

        // When the owner views the company's audit logs
        $response = $this->viewAuditLogs($owner, $company);

        // Then the list starts on the first day the plan keeps
        $response->assertOk();
        $this->assertListStartsOn($response, $firstDay);
    }

    /**
     * @return array<string, array{Closure(CompanyFactory): CompanyFactory, string}>
     */
    public static function providePlansAndTheirHorizons(): array
    {
        return [
            'free keeps one year' => [fn (CompanyFactory $company) => $company->onPlan(BillingPlan::Free), '2025-11-15'],
            'starter keeps one year' => [fn (CompanyFactory $company) => $company->onPlan(BillingPlan::Starter), '2025-11-15'],
            'pro keeps three years' => [fn (CompanyFactory $company) => $company->onPlan(BillingPlan::Pro), '2023-11-15'],
            'enterprise keeps seven years' => [fn (CompanyFactory $company) => $company->onPlan(BillingPlan::Enterprise), '2019-11-15'],
            'the trial keeps seven years' => [fn (CompanyFactory $company) => $company->onTrial(), '2019-11-15'],
            'an expired trial keeps one year' => [fn (CompanyFactory $company) => $company->trialExpired(), '2025-11-15'],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidAuditLogQueries(): array
    {
        $notSupported = 'This filter is not supported.';
        $notTwoDates = 'The within filter must be two ISO-8601 (YYYY-MM-DD) dates separated by a comma.';

        return [
            'unknown key' => [['sort' => 'time'], 'sort', $notSupported],
            'search by user before Pro search exists' => [['user' => 'someone'], 'user', $notSupported],
            'search by event before Pro search exists' => [['event' => 'login_failed'], 'event', $notSupported],
            'search by ip before Pro search exists' => [['ip' => '203.0.113.5'], 'ip', $notSupported],
            'per_page below floor' => [['per_page' => 0], 'per_page', 'The per page field must be at least 1.'],
            'per_page above cap' => [['per_page' => 101], 'per_page', 'The per page field must not be greater than 100.'],
            'per_page not a number' => [['per_page' => 'abc'], 'per_page', 'The per page field must be an integer.'],
            'page below floor' => [['page' => 0], 'page', 'The page field must be at least 1.'],
            'page not a number' => [['page' => 'abc'], 'page', 'The page field must be an integer.'],
            'within with one date' => [['within' => '2026-04-01'], 'within', $notTwoDates],
            'within with an impossible date' => [['within' => '2026-02-31,2026-03-01'], 'within', $notTwoDates],
            'array-valued within' => [['within' => ['2026-04-01']], 'within', 'The within field must be a string.'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEmptyFilterValues(): array
    {
        $values = [];

        foreach (['within', 'page', 'per_page'] as $field) {
            $values["empty {$field}"] = [$field, ''];
            $values["whitespace {$field}"] = [$field, ' '];
        }

        return $values;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideWriteVerbs(): array
    {
        return [
            'post' => ['POST'],
            'put' => ['PUT'],
            'patch' => ['PATCH'],
            'delete' => ['DELETE'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutTheAuditPermission(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'manager' => ['manager'],
        ];
    }
}
