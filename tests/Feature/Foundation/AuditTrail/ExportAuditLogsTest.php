<?php

namespace Tests\Feature\Foundation\AuditTrail;

use Closure;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
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
use App\Foundation\RolesPermissions\Entities\Role;
use App\Foundation\AccessLog\Entities\AccessLogEntry;
use App\Foundation\AccessLog\Enums\AccessEventReason;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;

class ExportAuditLogsTest extends AuditTrailTestCase
{
    /**
     * @var array<string, string>
     */
    protected const array CONTENT_TYPES = ['csv' => 'text/csv; charset=UTF-8', 'json' => 'application/json'];

    /**
     * @var list<string>
     */
    protected const array CSV_HEADER = [
        'id', 'kind', 'time', 'first_seen_at', 'occurrences', 'user_id', 'user_name',
        'event', 'event_words', 'outcome', 'outcome_words', 'ip_address', 'device',
    ];

    protected Company $enterprise;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 14:30:00');
        $this->enterprise = Company::factory()->onPlan(BillingPlan::Enterprise)->createOne();
        $this->owner = $this->aConsentingMember($this->enterprise, 'owner');
    }

    #[Test]
    public function when_a_guest_exports_the_audit_logs_then_it_is_unauthenticated(): void
    {
        // Given no one signed in

        // When a guest exports company A's audit logs
        $response = $this->exportAuditLogs(null, $this->enterprise, ['format' => 'csv']);

        // Then the guest is refused as unauthenticated
        $this->assertUnauthenticated($response);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutAuditView')]
    public function when_a_member_without_audit_view_exports_then_it_is_refused(string $roleCode): void
    {
        // Given a member of the Enterprise company whose role cannot view the audit logs
        $member = $this->aConsentingMember($this->enterprise, $roleCode);

        // When the member exports the company's audit logs
        $response = $this->exportAuditLogs($member, $this->enterprise, ['format' => 'csv']);

        // Then the role is refused
        $this->assertRoleInsufficient($response);
    }

    /**
     * @param  Closure(CompanyFactory): CompanyFactory  $standing
     */
    #[Test]
    #[DataProvider('provideStandingsWithoutExport')]
    public function when_the_company_is_below_enterprise_then_export_is_unavailable_with_an_enterprise_upgrade(Closure $standing): void
    {
        // Given a company whose plan has no log export, and its owner
        $company = $standing(Company::factory())->createOne();
        $owner = $this->aConsentingMember($company, 'owner');

        // When the owner exports the company's audit logs
        $response = $this->exportAuditLogs($owner, $company, ['format' => 'csv']);

        // Then export is refused with the plan that has it
        $this->assertExportUnavailable($response);
    }

    #[Test]
    public function when_the_export_is_refused_then_nothing_is_recorded(): void
    {
        // Given the owner of a Pro company
        $company = Company::factory()->onPlan(BillingPlan::Pro)->createOne();
        $owner = $this->aConsentingMember($company, 'owner');

        // When the owner tries to export the audit logs
        $response = $this->exportAuditLogs($owner, $company, ['format' => 'csv']);

        // Then the export is refused and no audit row records it
        $response->assertForbidden();
        $this->assertDatabaseCount('audit_trail', 0);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideInvalidFormats')]
    public function when_the_format_is_missing_or_unknown_then_it_is_refused(array $query, string $message): void
    {
        // Given the owner of the Enterprise company (setUp)

        // When the owner exports without a usable format
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, $query);

        // Then the format is rejected with the reason
        $this->assertValidationRejected($response, 'format', $message);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideInvalidFilters')]
    public function when_a_filter_value_is_malformed_then_it_is_refused(array $query, string $field, string $message): void
    {
        // Given the owner of the Enterprise company (setUp)

        // When the owner exports with the malformed filter
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => 'csv', ...$query]);

        // Then the filter is rejected with the reason
        $this->assertValidationRejected($response, $field, $message);
    }

    #[Test]
    #[DataProvider('provideUnsupportedKeys')]
    public function when_a_page_or_unknown_key_is_sent_then_it_is_refused(string $key): void
    {
        // Given the owner of the Enterprise company (setUp)

        // When the owner exports with a key the export does not take
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => 'csv', $key => '2']);

        // Then the key is rejected
        $this->assertValidationRejected($response, $key, 'This filter is not supported.');
    }

    #[Test]
    public function when_an_eleventh_export_is_made_within_the_hour_then_it_is_throttled(): void
    {
        // Given the owner has exported the audit logs ten times this hour
        $this->exhaustTheHourlyExports($this->owner, $this->enterprise);

        // When the owner exports an eleventh time
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => 'csv']);

        // Then the export is throttled
        $this->assertRefusedWith($response, Response::HTTP_TOO_MANY_REQUESTS, ApiErrorCode::RateLimitExceeded);
    }

    #[Test]
    public function when_an_hour_has_passed_then_the_export_is_allowed_again(): void
    {
        // Given the owner exported the audit logs ten times, an hour ago
        $this->exhaustTheHourlyExports($this->owner, $this->enterprise);
        $this->travel(61)->minutes();

        // When the owner exports again
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => 'csv']);

        // Then the export is served
        $response->assertOk();
    }

    /**
     * @param  Closure(self): array{User, Company}  $exporter
     */
    #[Test]
    #[DataProvider('provideOtherExporters')]
    public function when_another_exporter_is_unaffected_by_one_members_limit(Closure $exporter): void
    {
        // Given the owner has used this hour's ten exports of the Enterprise company
        $this->exhaustTheHourlyExports($this->owner, $this->enterprise);
        [$actor, $company] = $exporter($this);

        // When the other exporter exports
        $response = $this->exportAuditLogs($actor, $company, ['format' => 'csv']);

        // Then the export is served
        $response->assertOk();
    }

    #[Test]
    public function when_another_company_has_logs_then_its_rows_are_not_exported(): void
    {
        // Given a sign-in in the Enterprise company and one in company B
        $ours = $this->aSignInAt('2026-11-10 12:00:00')->createOne();
        $this->aSignInAt('2026-11-10 12:00:00')->forCompany($this->companyB)->createOne();

        // When the owner exports the audit logs
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => 'csv']);

        // Then only the company's own row is in the file
        $this->assertSame([$ours->getKey()], array_column($this->csvRowsOf($response), 'id'));
    }

    #[Test]
    public function when_rows_lie_past_the_horizon_then_the_export_starts_at_the_horizon(): void
    {
        // Given a sign-in on the horizon's first moment and one just before it
        $first = $this->aSignInAt('2019-11-15 00:00:00')->createOne();
        $this->aSignInAt('2019-11-14 23:59:59')->createOne();

        // When the owner exports from further back than the plan keeps
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => 'csv', 'within' => '2015-01-01,2026-11-15']);

        // Then the file starts at the horizon
        $this->assertSame([$first->getKey()], array_column($this->csvRowsOf($response), 'id'));
    }

    #[Test]
    #[DataProvider('provideFormats')]
    public function when_a_staff_row_is_exported_then_it_shows_lesily_support_without_address(string $format): void
    {
        // Given a change Lesily staff made in the Enterprise company
        AuditEntry::factory()
            ->forCompany($this->enterprise)
            ->byStaff(User::factory()->named('Staff Member')->createOne())
            ->ofAction(AuditAction::RoleChanged, AuditSubjectType::User)
            ->fromOrigin('192.0.2.44', 'Staff browser')
            ->recordedAt(CarbonImmutable::parse('2026-11-10 12:00:00'))
            ->createOne();

        // When the owner exports the audit logs
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => $format]);

        // Then the row names Lesily support and shows no address or device
        $expected = ['user_id' => null, 'user_name' => 'Lesily support', 'ip_address' => null, 'device' => null];
        $this->assertSame([$expected], array_map(
            fn (array $row): array => array_intersect_key($row, $expected),
            $this->exportedRowsOf($response, $format),
        ));
    }

    #[Test]
    #[DataProvider('provideEmptyFiles')]
    public function when_nothing_matches_then_the_file_holds_no_rows(string $format, string $file): void
    {
        // Given no audit-log rows in the Enterprise company

        // When the owner exports the audit logs
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => $format]);

        // Then the file holds no rows
        $this->assertSame($file, $response->streamedContent());
    }

    #[Test]
    #[DataProvider('provideSearchKeys')]
    public function when_a_search_filter_is_given_then_only_matching_rows_are_exported(string $key): void
    {
        // Given a member's failed sign-in and another person's sign-in from elsewhere
        $member = User::factory()->createOne();
        $match = $this->aSignInAt('2026-11-10 12:00:00')->byUser($member)
            ->ofEvent(AccessEvent::LoginFailed, AccessEventReason::WrongPassword)
            ->fromOrigin('203.0.113.5', 'Firefox on Linux')->createOne();
        $this->aSignInAt('2026-11-10 12:00:00')->fromOrigin('198.51.100.7', 'Edge on Windows')->createOne();
        $search = ['user' => $member->getKey(), 'event' => 'login_failed', 'ip' => '203.0.113.5'];

        // When the owner exports with the one search key
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => 'csv', $key => $search[$key]]);

        // Then only the matching row is in the file
        $this->assertSame([$match->getKey()], array_column($this->csvRowsOf($response), 'id'));
    }

    #[Test]
    public function when_rows_were_inserted_out_of_order_then_they_export_newest_first(): void
    {
        // Given two sign-ins sharing a time, and a newer one inserted between them
        $earlier = $this->aSignInAt('2026-11-10 10:00:00')->createOne();
        $newest = $this->aSignInAt('2026-11-10 12:00:00')->createOne();
        $sameTime = $this->aSignInAt('2026-11-10 10:00:00')->createOne();

        // When the owner exports the audit logs
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => 'csv']);

        // Then the newest comes first, and rows that share a time by id, newest first
        $this->assertSame(
            [$newest->getKey(), ...$this->newestIdFirst([$earlier->getKey(), $sameTime->getKey()])],
            array_column($this->csvRowsOf($response), 'id'),
        );
    }

    #[Test]
    #[DataProvider('provideFormulaStarts')]
    public function when_a_value_would_start_a_spreadsheet_formula_then_the_csv_keeps_it_as_text(string $start): void
    {
        // Given a sign-in whose device names a formula
        $this->aSignInAt('2026-11-10 12:00:00')->fromOrigin('203.0.113.5', "{$start}HYPERLINK(\"http://x.test\")")->createOne();

        // When the owner exports the audit logs as CSV
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => 'csv']);

        // Then the device is written as text, not as a formula
        $this->assertSame(["'{$start}HYPERLINK(\"http://x.test\")"], array_column($this->csvRowsOf($response), 'device'));
    }

    #[Test]
    public function when_a_date_range_is_given_then_only_its_rows_are_exported(): void
    {
        // Given sign-ins before, inside and after a date range
        $this->aSignInAt('2026-10-31 23:59:59')->createOne();
        $inside = $this->aSignInAt('2026-11-05 12:00:00')->createOne();
        $this->aSignInAt('2026-11-11 00:00:00')->createOne();

        // When the owner exports the range
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => 'csv', 'within' => '2026-11-01,2026-11-10']);

        // Then only the row inside the range is in the file
        $this->assertSame([$inside->getKey()], array_column($this->csvRowsOf($response), 'id'));
    }

    #[Test]
    public function when_the_logs_are_exported_then_the_file_stops_at_the_export_moment(): void
    {
        // Given a sign-in a second before the export and one at its moment
        $before = $this->aSignInAt('2026-11-15 14:29:59')->createOne();
        $this->aSignInAt('2026-11-15 14:30:00')->createOne();

        // When the owner exports the audit logs
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => 'csv']);

        // Then the file holds only what was logged before the export began, not the export's own row
        $this->assertSame([$before->getKey()], array_column($this->csvRowsOf($response), 'id'));
    }

    #[Test]
    public function when_a_live_trial_company_exports_then_it_is_served(): void
    {
        // Given a company on its trial, and its owner
        $company = Company::factory()->onTrial()->createOne();
        $owner = $this->aConsentingMember($company, 'owner');

        // When the owner exports the company's audit logs
        $response = $this->exportAuditLogs($owner, $company, ['format' => 'csv']);

        // Then the file is served
        $this->assertExportServed($response, 'csv');
    }

    #[Test]
    #[DataProvider('provideExportingRoles')]
    public function when_an_admin_or_auditor_exports_then_it_is_served(string $roleCode): void
    {
        // Given an admin or auditor of the Enterprise company
        $member = $this->aConsentingMember($this->enterprise, $roleCode);

        // When the member exports the company's audit logs
        $response = $this->exportAuditLogs($member, $this->enterprise, ['format' => 'csv']);

        // Then the file is served
        $this->assertExportServed($response, 'csv');
    }

    #[Test]
    public function when_an_enterprise_owner_exports_csv_then_the_rows_stream_as_a_csv_file(): void
    {
        // Given a member's folded failed sign-in and an admin's password change
        $member = User::factory()->named('Nimal Perera')->createOne();
        $signIn = AccessLogEntry::factory()
            ->forCompany($this->enterprise)
            ->byUser($member)
            ->ofEvent(AccessEvent::LoginFailed, AccessEventReason::WrongPassword)
            ->fromOrigin('203.0.113.5', 'Firefox on Linux')
            ->seenAt(CarbonImmutable::parse('2026-11-10 09:06:00'))
            ->foldedSince(CarbonImmutable::parse('2026-11-10 09:02:00'), 6)
            ->createOne();
        $change = AuditEntry::factory()
            ->forCompany($this->enterprise)
            ->byMember(User::factory()->named('Kamala Silva')->createOne(), 'admin')
            ->ofAction(AuditAction::PasswordChanged, AuditSubjectType::User)
            ->fromOrigin('198.51.100.20', 'Safari on macOS')
            ->recordedAt(CarbonImmutable::parse('2026-11-09 16:45:00'))
            ->createOne();

        // When the owner exports the audit logs as CSV
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => 'csv']);

        // Then the file holds both rows, newest first, every field shown
        $this->assertExportServed($response, 'csv');
        $this->assertSame([
            [
                'id' => $signIn->getKey(), 'kind' => 'access', 'time' => '2026-11-10T09:06:00.000000Z',
                'first_seen_at' => '2026-11-10T09:02:00.000000Z', 'occurrences' => '6',
                'user_id' => $member->getKey(), 'user_name' => 'Nimal Perera',
                'event' => 'login_failed', 'event_words' => 'Sign-in failed',
                'outcome' => 'wrong_password', 'outcome_words' => 'Wrong password',
                'ip_address' => '203.0.113.5', 'device' => 'Firefox on Linux',
            ],
            [
                'id' => $change->getKey(), 'kind' => 'audit', 'time' => '2026-11-09T16:45:00.000000Z',
                'first_seen_at' => '2026-11-09T16:45:00.000000Z', 'occurrences' => '1',
                'user_id' => $change->actor_user_id, 'user_name' => 'Kamala Silva',
                'event' => 'password_changed', 'event_words' => 'Password changed',
                'outcome' => '', 'outcome_words' => '',
                'ip_address' => '198.51.100.20', 'device' => 'Safari on macOS',
            ],
        ], $this->csvRowsOf($response));
    }

    #[Test]
    public function when_exported_as_structured_data_then_the_rows_stream_as_the_lists_rows(): void
    {
        // Given a member's folded failed sign-in and an admin's password change
        $this->aSignInAt('2026-11-10 09:06:00')
            ->ofEvent(AccessEvent::LoginFailed, AccessEventReason::WrongPassword)
            ->foldedSince(CarbonImmutable::parse('2026-11-10 09:02:00'), 6)
            ->createOne();
        $this->aChangeAt('2026-11-09 16:45:00')->createOne();
        $listed = $this->viewAuditLogs($this->owner, $this->enterprise, ['per_page' => 100])->json('data');

        // When the owner exports the audit logs as JSON
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => 'json']);

        // Then the file holds the rows the list showed, in the list's order
        $this->assertExportServed($response, 'json');
        $this->assertCount(2, $listed);
        $this->assertSame($listed, $this->exportedRowsOf($response, 'json'));
    }

    #[Test]
    public function when_the_logs_are_exported_then_one_warning_row_records_the_format_range_and_filters(): void
    {
        // Given the owner of the Enterprise company (setUp)

        // When the owner exports their own failed sign-ins from one address, from further back than the plan keeps, as JSON
        $response = $this->exportAuditLogs($this->owner, $this->enterprise, [
            'format' => 'json', 'within' => '2015-01-01,2026-11-14',
            'user' => $this->owner->getKey(), 'event' => 'login_failed', 'ip' => '203.0.113.5',
        ]);

        // Then one warning row records the export: the company, the range exported and the filters used
        $response->assertOk();
        $this->assertExportRecorded([
            'format' => 'json', 'from' => '2019-11-15', 'until' => '2026-11-14',
            'user' => $this->owner->getKey(), 'event' => 'login_failed', 'ip' => '203.0.113.5',
        ]);
    }

    #[Test]
    public function when_the_logs_are_exported_then_the_export_shows_in_the_audit_logs(): void
    {
        // Given the owner exported the audit logs
        $this->exportAuditLogs($this->owner, $this->enterprise, ['format' => 'csv'])->assertOk();

        // When the owner searches the audit logs for exports
        $response = $this->viewAuditLogs($this->owner, $this->enterprise, ['event' => 'audit_logs_exported']);

        // Then the export is listed under the owner's name
        $response->assertOk();
        $this->assertSame([['event' => 'audit_logs_exported', 'user_id' => $this->owner->getKey()]], array_map(
            fn (array $row): array => ['event' => $row['event'], 'user_id' => $row['user_id']],
            $response->json('data'),
        ));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutAuditView(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
        ];
    }

    /**
     * @return array<string, array{Closure(CompanyFactory): CompanyFactory}>
     */
    public static function provideStandingsWithoutExport(): array
    {
        return [
            'free' => [fn (CompanyFactory $company) => $company->onPlan(BillingPlan::Free)],
            'starter' => [fn (CompanyFactory $company) => $company->onPlan(BillingPlan::Starter)],
            'pro' => [fn (CompanyFactory $company) => $company->onPlan(BillingPlan::Pro)],
            'an expired trial' => [fn (CompanyFactory $company) => $company->trialExpired()],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidFormats(): array
    {
        return [
            'no format' => [[], 'The format field is required.'],
            'empty format' => [['format' => ''], 'The format field is required.'],
            'a format not offered' => [['format' => 'xlsx'], 'The selected format is invalid.'],
            'array-valued format' => [['format' => ['csv']], 'The selected format is invalid.'],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidFilters(): array
    {
        return [
            'a range that is not two dates' => [['within' => 'last-week'], 'within', 'The within filter must be two ISO-8601 (YYYY-MM-DD) dates separated by a comma.'],
            'user neither a member id nor lesily_support' => [['user' => 'someone'], 'user', "The user filter must be a member's id or lesily_support."],
            'event the audit logs never show' => [['event' => 'user_invited'], 'event', 'The selected event is invalid.'],
            'ip that is only part of an address' => [['ip' => '203.0.113'], 'ip', 'The ip field must be a valid IP address.'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnsupportedKeys(): array
    {
        return [
            'page' => ['page'],
            'per page' => ['per_page'],
            'sort' => ['sort'],
        ];
    }

    /**
     * @return array<string, array{Closure(self): array{User, Company}}>
     */
    public static function provideOtherExporters(): array
    {
        return [
            'another member of the company' => [fn (self $test) => [$test->aConsentingMember($test->enterprise, 'admin'), $test->enterprise]],
            'the same owner in another company' => [fn (self $test) => $test->theOwnerInAnotherEnterprise()],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideFormulaStarts(): array
    {
        return [
            'equals' => ['='],
            'plus' => ['+'],
            'minus' => ['-'],
            'at' => ['@'],
            'tab' => ["\t"],
            'carriage return' => ["\r"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideFormats(): array
    {
        return [
            'csv' => ['csv'],
            'json' => ['json'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEmptyFiles(): array
    {
        return [
            'csv: the header only' => ['csv', "id,kind,time,first_seen_at,occurrences,user_id,user_name,event,event_words,outcome,outcome_words,ip_address,device\n"],
            'json: an empty list' => ['json', '[]'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideSearchKeys(): array
    {
        return [
            'by user' => ['user'],
            'by event' => ['event'],
            'by ip' => ['ip'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideExportingRoles(): array
    {
        return [
            'admin' => ['admin'],
            'auditor' => ['auditor'],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function exportAuditLogs(?User $actor, Company $company, array $query): TestResponse
    {
        if ($actor !== null) {
            Sanctum::actingAs($actor);
        }

        return $this->getJson('/api/v1/audit-logs/export?'.http_build_query($query), ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * @return array{User, Company}
     */
    protected function theOwnerInAnotherEnterprise(): array
    {
        $company = Company::factory()->onPlan(BillingPlan::Enterprise)->createOne();
        $this->attachMember($company, $this->owner, Role::query()->where('code', 'owner')->sole());
        $this->aConsentRecordedBy($this->owner, $company);

        return [$this->owner, $company];
    }

    protected function exhaustTheHourlyExports(User $actor, Company $company): void
    {
        for ($export = 0; $export < 10; $export++) {
            $this->exportAuditLogs($actor, $company, ['format' => 'csv']);
        }
    }

    protected function aSignInAt(string $seenAt): AccessLogEntryFactory
    {
        return AccessLogEntry::factory()
            ->forCompany($this->enterprise)
            ->byUser(User::factory()->createOne())
            ->seenAt(CarbonImmutable::parse($seenAt));
    }

    protected function aChangeAt(string $recordedAt): AuditEntryFactory
    {
        return AuditEntry::factory()
            ->forCompany($this->enterprise)
            ->byMember(User::factory()->createOne(), 'admin')
            ->ofAction(AuditAction::PasswordChanged, AuditSubjectType::User)
            ->recordedAt(CarbonImmutable::parse($recordedAt));
    }

    /**
     * The exported rows, each keyed by its field names; a CSV's empty cell reads as null.
     *
     * @return list<array<string, mixed>>
     */
    protected function exportedRowsOf(TestResponse $response, string $format): array
    {
        if ($format === 'json') {
            return json_decode((string) $response->streamedContent(), associative: true, flags: JSON_THROW_ON_ERROR);
        }

        return array_map(
            fn (array $row): array => array_map(fn (?string $cell): ?string => $cell === '' ? null : $cell, $row),
            $this->csvRowsOf($response),
        );
    }

    /**
     * The CSV's lines after its header, each keyed by the header's names.
     *
     * @return list<array<string, ?string>>
     */
    protected function csvRowsOf(TestResponse $response): array
    {
        $lines = array_map(str_getcsv(...), explode("\n", rtrim((string) $response->streamedContent(), "\n")));
        $header = array_shift($lines);

        $this->assertSame(self::CSV_HEADER, $header);

        return array_map(fn (array $line): array => array_combine(self::CSV_HEADER, $line), $lines);
    }

    protected function assertExportServed(TestResponse $response, string $format): void
    {
        $response->assertOk();
        $response->assertDownload("audit-logs-2026-11-15.{$format}");
        $response->assertHeader('Content-Type', self::CONTENT_TYPES[$format]);
    }

    /**
     * @param  array<string, string>  $metadata
     */
    protected function assertExportRecorded(array $metadata): void
    {
        $entry = AuditEntry::query()->sole();

        $this->assertSame(
            [AuditAction::AuditLogsExported, 'warning', AuditSubjectType::Company, $this->enterprise->getKey(), $this->owner->getKey()],
            [$entry->action, $entry->severity, $entry->subject_type, $entry->subject_id, $entry->actor_user_id],
        );
        $this->assertSame($this->enterprise->getKey(), $entry->company_id);
        $stored = $entry->metadata;
        $this->assertIsArray($stored);
        ksort($stored);
        ksort($metadata);
        $this->assertSame($metadata, $stored);
    }

    protected function assertExportUnavailable(TestResponse $response): void
    {
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::FeatureNotAvailable);
        $response->assertJsonPath('upgrade_to', 'Enterprise');
        $response->assertJsonPath('upgrade_url', '/settings/billing/upgrade');
    }
}
