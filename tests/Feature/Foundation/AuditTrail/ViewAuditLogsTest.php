<?php

namespace Tests\Feature\Foundation\AuditTrail;

use Closure;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
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
use App\Foundation\RolesPermissions\Entities\Role;
use Tests\Concerns\AssertsFilterEmptyValueRejection;
use App\Foundation\AccessLog\Entities\AccessLogEntry;
use App\Foundation\AccessLog\Enums\AccessEventReason;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\AuditTrail\UseCases\Commands\ListAuditLogsCommand;

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
        $this->assertEmptyFilterValueRejected(ListAuditLogsCommand::class, $field, $value);
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
    public function when_rows_share_a_time_then_the_newer_id_comes_first(): void
    {
        // Given a sign-in and a password change in company A at the same second
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        $passwordChange = $this->aSecurityEntryOfCompanyAAt('2026-11-15 10:00:00');
        $signIn = $this->anAccessRowOfCompanyAAt('2026-11-15 10:00:00');

        // When the owner views company A's audit logs
        $response = $this->viewAuditLogs($owner, $this->companyA);

        // Then the row with the newer id comes first
        $this->assertEntriesListed($response, $this->newestIdFirst([$passwordChange->getKey(), $signIn->getKey()]));
    }

    #[Test]
    public function when_listed_page_by_page_then_each_page_holds_the_next_rows(): void
    {
        // Given three sign-ins and two password changes in company A, an hour apart
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        $rows = [
            $this->anAccessRowOfCompanyAAt('2026-11-15 13:00:00'),
            $this->aSecurityEntryOfCompanyAAt('2026-11-15 12:00:00'),
            $this->anAccessRowOfCompanyAAt('2026-11-15 11:00:00'),
            $this->aSecurityEntryOfCompanyAAt('2026-11-15 10:00:00'),
            $this->anAccessRowOfCompanyAAt('2026-11-15 09:00:00'),
        ];

        // When the owner views the second page of two
        $response = $this->viewAuditLogs($owner, $this->companyA, ['per_page' => 2, 'page' => 2]);

        // Then the page holds the third and fourth rows, and says where it is
        $this->assertEntriesListed($response, [$rows[2]->getKey(), $rows[3]->getKey()]);
        $this->assertPageShown($response, ['page' => 2, 'per_page' => 2, 'total' => 5, 'last_page' => 3]);
    }

    #[Test]
    public function when_no_page_size_is_given_then_fifteen_rows_are_shown(): void
    {
        // Given sixteen sign-ins in company A
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        AccessLogEntry::factory()->count(16)->forCompany($this->companyA)->create();

        // When the owner views company A's audit logs
        $response = $this->viewAuditLogs($owner, $this->companyA);

        // Then fifteen rows are shown on the first of two pages
        $response->assertJsonCount(15, 'data');
        $this->assertPageShown($response, ['page' => 1, 'per_page' => 15, 'total' => 16, 'last_page' => 2]);
    }

    #[Test]
    public function when_a_page_of_one_hundred_is_asked_then_it_is_given(): void
    {
        // Given one hundred and one sign-ins in company A
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        AccessLogEntry::factory()->count(101)->forCompany($this->companyA)->create();

        // When the owner asks for pages of one hundred
        $response = $this->viewAuditLogs($owner, $this->companyA, ['per_page' => 100]);

        // Then one hundred rows are shown
        $response->assertJsonCount(100, 'data');
        $this->assertPageShown($response, ['page' => 1, 'per_page' => 100, 'total' => 101, 'last_page' => 2]);
    }

    #[Test]
    public function when_a_page_is_sent_outside_the_query_string_then_it_is_ignored(): void
    {
        // Given two sign-ins in company A
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        $newer = $this->anAccessRowOfCompanyAAt('2026-11-15 10:00:00');
        $this->anAccessRowOfCompanyAAt('2026-11-15 09:00:00');
        Sanctum::actingAs($owner);

        // When the owner asks for pages of one, with page 2 in the request body
        $response = $this->json('GET', '/api/v1/audit-logs?per_page=1', ['page' => 2], ['X-Company-Context' => $this->companyA->getKey()]);

        // Then the first page is shown
        $this->assertEntriesListed($response, [$newer->getKey()]);
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
    public function when_the_dates_reach_past_the_horizon_then_the_list_is_clamped_to_it(): void
    {
        // Given company A on the Free plan, with a sign-in inside its year and one before it
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        $inside = $this->anAccessRowOfCompanyAAt('2026-06-01 12:00:00');
        $this->anAccessRowOfCompanyAAt('2025-06-01 12:00:00');

        // When the owner asks for the audit logs from 2024
        $response = $this->viewAuditLogs($owner, $this->companyA, ['within' => '2024-01-01,2026-11-15']);

        // Then only the row inside the plan's year is listed, and the list says where it starts
        $this->assertEntriesListed($response, [$inside->getKey()]);
        $this->assertListStartsOn($response, '2025-11-15');
    }

    #[Test]
    public function when_the_dates_lie_wholly_before_the_horizon_then_the_list_is_empty(): void
    {
        // Given company A on the Free plan, with a sign-in in 2024
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        $this->anAccessRowOfCompanyAAt('2024-06-01 12:00:00');

        // When the owner asks for the audit logs of 2024
        $response = $this->viewAuditLogs($owner, $this->companyA, ['within' => '2024-01-01,2024-12-31']);

        // Then nothing is listed, and the list says where it starts
        $this->assertNothingListed($response);
        $this->assertListStartsOn($response, '2025-11-15');
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
    public function when_a_staff_member_changed_a_role_then_it_is_shown_as_lesily_support(): void
    {
        // Given Lesily staff changed a role in company A, from their own address and device
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        AuditEntry::factory()->forCompany($this->companyA)->byStaff(User::factory()->named('Sam Staff')->create())
            ->ofAction(AuditAction::RoleChanged, AuditSubjectType::User)
            ->fromOrigin('192.0.2.44', 'StaffConsole/1')->create();

        // When the owner views company A's audit logs
        $response = $this->viewAuditLogs($owner, $this->companyA);

        // Then the change shows as Lesily support, never the staff member, their address or device
        $this->assertRowShows($response, 0, [
            'user_id' => null,
            'user_name' => 'Lesily support',
            'ip_address' => null,
            'device' => null,
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

    #[Test]
    public function when_the_company_has_sign_ins_and_security_changes_then_they_are_listed_together_newest_first(): void
    {
        // Given company A recorded, out of time order, Carol's failed sign-in at 11:00, Alice's password change at 09:00
        // and Bob's sign-in at 10:00, each from its own address and device
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        $carol = User::factory()->named('Carol')->create();
        $alice = User::factory()->named('Alice')->create();
        $bob = User::factory()->named('Bob')->create();
        $failedSignIn = AccessLogEntry::factory()->forCompany($this->companyA)->byUser($carol)
            ->ofEvent(AccessEvent::LoginFailed, AccessEventReason::WrongPassword)
            ->seenAt(CarbonImmutable::parse('2026-11-15 11:00:00'))->fromOrigin('203.0.113.9', 'Firefox/140')->create();
        $passwordChange = AuditEntry::factory()->forCompany($this->companyA)->byMember($alice, 'admin')
            ->ofAction(AuditAction::PasswordChanged, AuditSubjectType::User)
            ->recordedAt(CarbonImmutable::parse('2026-11-15 09:00:00'))->fromOrigin('198.51.100.20', 'Chrome/141')->create();
        $signIn = AccessLogEntry::factory()->forCompany($this->companyA)->byUser($bob)
            ->seenAt(CarbonImmutable::parse('2026-11-15 10:00:00'))->fromOrigin('203.0.113.5', 'Safari/18')->create();

        // When the owner views company A's audit logs
        $response = $this->viewAuditLogs($owner, $this->companyA);

        // Then the three rows are listed together, newest first, each with what happened, who, when and where
        $response->assertOk();
        $this->assertSame([
            [
                'id' => $failedSignIn->getKey(), 'kind' => 'access',
                'time' => '2026-11-15T11:00:00.000000Z', 'first_seen_at' => '2026-11-15T11:00:00.000000Z', 'occurrences' => 1,
                'user_id' => $carol->getKey(), 'user_name' => 'Carol',
                'event' => 'login_failed', 'event_words' => 'Sign-in failed',
                'outcome' => 'wrong_password', 'outcome_words' => 'Wrong password',
                'ip_address' => '203.0.113.9', 'device' => 'Firefox/140',
            ],
            [
                'id' => $signIn->getKey(), 'kind' => 'access',
                'time' => '2026-11-15T10:00:00.000000Z', 'first_seen_at' => '2026-11-15T10:00:00.000000Z', 'occurrences' => 1,
                'user_id' => $bob->getKey(), 'user_name' => 'Bob',
                'event' => 'login_succeeded', 'event_words' => 'Signed in',
                'outcome' => null, 'outcome_words' => null,
                'ip_address' => '203.0.113.5', 'device' => 'Safari/18',
            ],
            [
                'id' => $passwordChange->getKey(), 'kind' => 'audit',
                'time' => '2026-11-15T09:00:00.000000Z', 'first_seen_at' => '2026-11-15T09:00:00.000000Z', 'occurrences' => 1,
                'user_id' => $alice->getKey(), 'user_name' => 'Alice',
                'event' => 'password_changed', 'event_words' => 'Password changed',
                'outcome' => null, 'outcome_words' => null,
                'ip_address' => '198.51.100.20', 'device' => 'Chrome/141',
            ],
        ], $response->json('data'));
    }

    #[Test]
    #[DataProvider('provideSecurityActions')]
    public function when_a_security_change_is_recorded_then_it_is_listed(AuditAction $action, AuditSubjectType $subjectType): void
    {
        // Given company A recorded the security change, and its owner
        $entry = $this->anEntryOfCompanyAFor($action, $subjectType);
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit logs
        $response = $this->viewAuditLogs($owner, $this->companyA);

        // Then the change is listed
        $this->assertEntriesListed($response, [$entry->getKey()]);
    }

    /**
     * @return array<string, array{AuditAction, AuditSubjectType}>
     */
    public static function provideSecurityActions(): array
    {
        return [
            'password changed' => [AuditAction::PasswordChanged, AuditSubjectType::User],
            'password reset' => [AuditAction::PasswordReset, AuditSubjectType::User],
            'two-factor enabled' => [AuditAction::TwoFactorEnabled, AuditSubjectType::User],
            'two-factor disabled' => [AuditAction::TwoFactorDisabled, AuditSubjectType::User],
            'recovery codes regenerated' => [AuditAction::RecoveryCodesRegenerated, AuditSubjectType::User],
            'role changed' => [AuditAction::RoleChanged, AuditSubjectType::User],
            'member removed' => [AuditAction::MemberRemoved, AuditSubjectType::User],
            'data request downloaded' => [AuditAction::DsarDownloaded, AuditSubjectType::DsarRequest],
        ];
    }

    #[Test]
    #[DataProvider('provideRolesWithTheAuditPermission')]
    public function when_permitted_roles_view_then_the_logs_are_shown(string $roleCode): void
    {
        // Given a sign-in in company A, and a member of A whose role may view the audit logs
        $signIn = $this->anAccessRowOfCompanyAAt('2026-11-15 10:00:00');
        $member = $this->aConsentingMember($this->companyA, $roleCode);

        // When the member views company A's audit logs
        $response = $this->viewAuditLogs($member, $this->companyA);

        // Then the sign-in is listed
        $this->assertEntriesListed($response, [$signIn->getKey()]);
    }

    #[Test]
    public function when_the_audit_logs_are_viewed_then_no_audit_entry_is_written(): void
    {
        // Given a sign-in in company A, and its owner
        $this->anAccessRowOfCompanyAAt('2026-11-15 10:00:00');
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner views company A's audit logs
        $response = $this->viewAuditLogs($owner, $this->companyA);

        // Then the view leaves no audit-trail row
        $response->assertOk();
        $this->assertDatabaseCount('audit_trail', 0);
    }

    #[Test]
    public function when_a_member_signs_in_then_the_sign_in_reaches_the_company_audit_logs(): void
    {
        // Given Alice, a member of company A and of company B, and A's owner
        $alice = User::factory()->named('Alice')->withPassword('Str0ng-Pass!')->create();
        $employee = Role::query()->where('code', 'employee')->sole();
        $this->attachMember($this->companyA, $alice, $employee);
        $this->attachMember($this->companyB, $alice, $employee);
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        $this->postJson('/api/v1/auth/login', ['email' => $alice->email, 'password' => 'Str0ng-Pass!']);

        // When the owner views company A's audit logs
        $response = $this->viewAuditLogs($owner, $this->companyA);

        // Then Alice's sign-in is listed
        $response->assertJsonCount(1, 'data');
        $this->assertRowShows($response, 0, ['kind' => 'access', 'user_id' => $alice->getKey(), 'user_name' => 'Alice', 'event' => 'login_succeeded']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithTheAuditPermission(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
            'auditor' => ['auditor'],
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
        /** @var Company $company */
        $company = $standing(Company::factory())->create();
        $owner = $this->aConsentingMember($company, 'owner');

        // When the owner views the company's audit logs
        $response = $this->viewAuditLogs($owner, $company);

        // Then the list starts on the first day the plan keeps
        $response->assertOk();
        $this->assertListStartsOn($response, $firstDay);
    }

    #[Test]
    public function when_filtered_by_date_then_only_rows_in_range_are_listed(): void
    {
        // Given company A on Enterprise, with a sign-in and a password change on each side of each end of April
        $company = Company::factory()->onPlan(BillingPlan::Enterprise)->create();
        $owner = $this->aConsentingMember($company, 'owner');
        $this->companyA = $company;
        $this->anAccessRowOfCompanyAAt('2026-03-31 23:59:59');
        $this->aSecurityEntryOfCompanyAAt('2026-03-31 23:59:59');
        $firstSignIn = $this->anAccessRowOfCompanyAAt('2026-04-01 00:00:00');
        $firstPasswordChange = $this->aSecurityEntryOfCompanyAAt('2026-04-01 00:00:00');
        $lastSignIn = $this->anAccessRowOfCompanyAAt('2026-04-30 23:59:59');
        $lastPasswordChange = $this->aSecurityEntryOfCompanyAAt('2026-04-30 23:59:59');
        $this->anAccessRowOfCompanyAAt('2026-05-01 00:00:00');
        $this->aSecurityEntryOfCompanyAAt('2026-05-01 00:00:00');

        // When the owner views the audit logs within April
        $response = $this->viewAuditLogs($owner, $company, ['within' => '2026-04-01,2026-04-30']);

        // Then both ends of April are inside, newest first, and nothing outside
        $this->assertEntriesListed($response, [
            ...$this->newestIdFirst([$lastSignIn->getKey(), $lastPasswordChange->getKey()]),
            ...$this->newestIdFirst([$firstSignIn->getKey(), $firstPasswordChange->getKey()]),
        ]);
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
