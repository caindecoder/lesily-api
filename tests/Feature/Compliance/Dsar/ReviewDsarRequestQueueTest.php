<?php

namespace Tests\Feature\Compliance\Dsar;

use LogicException;
use App\Models\User;
use RuntimeException;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use Tests\Concerns\AssertsFilterEmptyValueRejection;
use App\Compliance\Dsar\UseCases\Commands\DsarRequestAdminFilters;

class ReviewDsarRequestQueueTest extends DsarTestCase
{
    use AssertsFilterEmptyValueRejection;

    protected Company $company;

    protected Company $otherCompany;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->otherCompany = Company::factory()->create();
        $this->admin = $this->aPlatformAdmin();
    }

    #[Test]
    public function when_no_token_is_presented_then_the_queue_request_is_rejected_as_unauthenticated(): void
    {
        // Given a request in a company
        DsarRequestRecord::factory()->forSubject($this->company, $this->aCompanyMember($this->company))->create();

        // When a guest lists the queue
        $response = $this->listQueue(null);

        // Then the guest is refused and no read is recorded
        $this->assertUnauthenticated($response);
        $this->assertNoQueueReadRecorded();
    }

    #[Test]
    #[DataProvider('provideTenantRolesDeniedTheAdminSurface')]
    public function when_a_tenant_member_requests_the_dsar_queue_then_the_admin_surface_is_not_acknowledged(string $roleCode): void
    {
        // Given a member of a company in this role, not a platform admin, and a request in their own company
        $tenantMember = $this->aCompanyMember($this->company, $roleCode);
        DsarRequestRecord::factory()->forSubject($this->company, $tenantMember)->create();

        // When they list the queue
        $response = $this->listQueue($tenantMember);

        // Then the admin surface is not acknowledged — not even their own company's request — and no read is recorded
        $this->assertResourceNotFound($response);
        $response->assertJsonMissingPath('data');
        $this->assertNoQueueReadRecorded();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideInvalidQueueQueries')]
    public function when_the_queue_query_is_invalid_then_the_read_is_refused(array $query, string $field, string $message): void
    {
        // Given a platform admin and a request in a company
        DsarRequestRecord::factory()->forSubject($this->company, $this->aCompanyMember($this->company))->create();

        // When the admin lists the queue with this row's defective query
        $response = $this->listQueue($this->admin, $query);

        // Then the read is refused naming the offending key and no read is recorded
        $this->assertValidationRejected($response, $field, $message);
        $this->assertNoQueueReadRecorded();
    }

    #[Test]
    #[DataProvider('provideEmptyAdminFilterValues')]
    public function when_a_dsar_admin_filter_is_built_with_an_empty_value_then_it_is_rejected(string $field, string $value): void
    {
        // Given an empty value for a whitelisted filter key — reachable only at
        // the DTO seam (TrimStrings + ConvertEmptyStringsToNull neutralise it over HTTP)

        // When the filter is built directly, then construction is rejected on that field
        $this->assertEmptyFilterValueRejected(DsarRequestAdminFilters::class, $field, $value);
    }

    #[Test]
    public function when_the_audit_write_fails_then_the_queue_returns_no_request_data(): void
    {
        // Given a request, and an audit write that fails AFTER its row is
        // inserted — only the read's transaction can take the row back out
        DsarRequestRecord::factory()->forSubject($this->company, $this->aCompanyMember($this->company))->create();
        AuditEntry::created(fn () => throw new RuntimeException('induced audit failure'));

        // When the admin lists the queue
        $response = $this->listQueue($this->admin);

        // Then the read fails closed — no request data, and the evidence row was rolled back with it
        $this->assertFailedClosed($response);
        $this->assertNoQueueReadRecorded();
    }

    #[Test]
    public function when_no_requests_match_then_the_queue_is_empty_with_zero_kpis_and_the_read_is_still_recorded(): void
    {
        // Given a platform admin and no DSAR requests anywhere
        // When the admin lists the queue
        $response = $this->listQueue($this->admin);

        // Then the page and every count are empty, and the read still left its evidence
        $this->assertListed($response, []);
        $this->assertKpis($response, ['open' => 0, 'overdue' => 0, 'due_in_7_days' => 0]);
        $this->assertQueueReadAudited($response, ['filters' => [], 'result_count' => 0, 'page' => 1]);
    }

    /**
     * @param  array{open: int, overdue: int, due_in_7_days: int}  $kpis
     */
    #[Test]
    #[DataProvider('provideDeadlinesAroundToday')]
    public function when_deadlines_fall_around_today_then_the_kpis_count_calendar_days(string $deadline, array $kpis): void
    {
        // Given one in-progress request due at this row's instant — the clock reads 1 Oct 09:00 UTC
        $this->aRequestDue($this->company, $deadline);

        // When the admin lists the queue
        $response = $this->listQueue($this->admin);

        // Then the counts place it by calendar day: overdue through the deadline day, due within seven days after it
        $this->assertKpis($response, $kpis);
    }

    #[Test]
    #[DataProvider('provideClosedStates')]
    public function when_a_request_is_no_longer_in_progress_then_the_kpis_leave_it_out(string $state): void
    {
        // Given one request closed this row's way, its deadline passed
        $closed = $this->aRequestDue($this->company, '2026-09-25 09:00:00', $state);

        // When the admin lists the queue
        $response = $this->listQueue($this->admin);

        // Then it is listed, but no count includes it — open, overdue and due-soon are in-progress counts
        $this->assertListed($response, [$closed]);
        $this->assertKpis($response, ['open' => 0, 'overdue' => 0, 'due_in_7_days' => 0]);
    }

    #[Test]
    public function when_the_queue_lists_several_requests_then_it_reads_them_with_fewer_queries_than_rows(): void
    {
        // Given fifteen requests spread over two companies
        foreach (range(1, 15) as $day) {
            $this->aRequestDue($day % 2 === 0 ? $this->company : $this->otherCompany, sprintf('2026-10-%02d 09:00:00', $day + 1));
        }

        // When the admin lists the queue with the query log on
        [$response, $queryCount] = $this->listQueueCountingQueries();

        // Then the page cost fewer queries than it has rows — company, subject and requester load once per page
        $this->assertListedWithFewerQueriesThanRows($response, $queryCount);
    }

    #[Test]
    public function when_requests_exist_across_companies_then_the_queue_lists_them_soonest_deadline_first(): void
    {
        // Given a request in one company due in three days, and one in another company due tomorrow
        $dueLater = $this->aRequestDue($this->company, '2026-10-04 09:00:00');
        $dueSooner = $this->aRequestDue($this->otherCompany, '2026-10-02 09:00:00');

        // When the admin lists the queue
        $response = $this->listQueue($this->admin);

        // Then both companies' requests are listed soonest deadline first, as admin rows, with the counts
        $response->assertOk();
        $response->assertExactJson([
            'data' => [$this->adminRowOf($dueSooner, 1), $this->adminRowOf($dueLater, 3)],
            'meta' => ['pagination' => ['page' => 1, 'per_page' => 15, 'total' => 2, 'last_page' => 1]],
            'kpis' => ['open' => 2, 'overdue' => 0, 'due_in_7_days' => 2],
        ]);
        $this->assertQueueReadAudited($response, ['filters' => [], 'result_count' => 2, 'page' => 1]);
    }

    #[Test]
    public function when_deadlines_tie_then_the_order_falls_back_to_the_id(): void
    {
        // Given three requests due at the same instant, created highest id first
        $tied = array_map(
            fn (string $id): DsarRequestRecord => DsarRequestRecord::factory()
                ->forSubject($this->company, $this->aCompanyMember($this->company))
                ->identifiedAs($id)
                ->dueAt('2026-10-20 09:00:00')
                ->create(),
            ['0199a3f0-0000-7000-8000-000000000003', '0199a3f0-0000-7000-8000-000000000002', '0199a3f0-0000-7000-8000-000000000001'],
        );

        // When the admin lists the queue
        $response = $this->listQueue($this->admin);

        // Then the tie falls back to the lowest id first — pages can never duplicate or skip a tied request
        $this->assertListed($response, array_reverse($tied));
    }

    /**
     * @param  array{open: int, overdue: int, due_in_7_days: int}  $kpis
     */
    #[Test]
    #[DataProvider('provideStatusFilters')]
    public function when_the_queue_is_filtered_by_status_then_only_matching_requests_are_listed_and_the_kpis_follow(string $status, array $kpis): void
    {
        // Given one request in each status
        $requests = [];
        foreach (['in_progress', 'fulfilled', 'rejected', 'cancelled'] as $state) {
            $requests[$state] = $this->aRequestDue($this->company, '2026-10-20 09:00:00', $state);
        }

        // When the admin lists the queue filtered by this row's status
        $response = $this->listQueue($this->admin, ['status' => $status]);

        // Then only that status is listed, the counts follow the filter, and the filter is recorded
        $this->assertListed($response, [$requests[$status]]);
        $this->assertKpis($response, $kpis);
        $this->assertQueueReadAudited($response, ['filters' => ['status' => $status], 'result_count' => 1, 'page' => 1]);
    }

    #[Test]
    public function when_the_queue_is_filtered_by_company_then_only_that_companys_requests_and_kpis_are_shown(): void
    {
        // Given two requests in one company — one overdue, one due in four days — and an overdue one in another company
        $overdue = $this->aRequestDue($this->company, '2026-09-30 09:00:00');
        $dueSoon = $this->aRequestDue($this->company, '2026-10-05 09:00:00');
        $this->aRequestDue($this->otherCompany, '2026-09-29 09:00:00');

        // When the admin lists the queue for the first company
        $response = $this->listQueue($this->admin, ['company_id' => $this->company->getKey()]);

        // Then only that company's requests are listed and counted, and the filter is recorded
        $this->assertListed($response, [$overdue, $dueSoon]);
        $this->assertKpis($response, ['open' => 2, 'overdue' => 1, 'due_in_7_days' => 1]);
        $this->assertQueueReadAudited($response, ['filters' => ['company_id' => $this->company->getKey()], 'result_count' => 2, 'page' => 1]);
    }

    #[Test]
    public function when_overdue_only_is_toggled_then_only_overdue_requests_are_listed_and_the_kpis_recompute(): void
    {
        // Given in-progress requests two days late and due today (both overdue — 0 days or fewer left),
        // one in progress due at the first second of tomorrow, and a fulfilled one whose deadline passed
        $dueToday = $this->aRequestDue($this->company, '2026-10-01 09:00:00');
        $twoDaysLate = $this->aRequestDue($this->otherCompany, '2026-09-29 09:00:00');
        $this->aRequestDue($this->company, '2026-10-02 00:00:00');
        $this->aRequestDue($this->otherCompany, '2026-09-25 09:00:00', 'fulfilled');

        // When the admin toggles "Overdue only"
        $response = $this->listQueue($this->admin, ['overdue' => 'true']);

        // Then only the overdue in-progress requests are listed, soonest deadline first, the counts recompute
        // against them, and the filter is recorded
        $this->assertListed($response, [$twoDaysLate, $dueToday]);
        $this->assertKpis($response, ['open' => 2, 'overdue' => 2, 'due_in_7_days' => 0]);
        $this->assertQueueReadAudited($response, ['filters' => ['overdue' => 'true'], 'result_count' => 2, 'page' => 1]);
    }

    #[Test]
    public function when_breached_is_toggled_then_only_flagged_requests_are_listed(): void
    {
        // Given a flagged request still in progress, a flagged one fulfilled late, and an overdue one the sweep has not flagged
        $flaggedInProgress = $this->aRequestDue($this->company, '2026-09-28 09:00:00', 'breached');
        $flaggedThenFulfilled = $this->aRequestDue($this->otherCompany, '2026-09-26 09:00:00', 'breached_fulfilled');
        $this->aRequestDue($this->company, '2026-09-30 09:00:00');

        // When the admin toggles "Breached"
        $response = $this->listQueue($this->admin, ['sla_breached' => 'true']);

        // Then only the flagged requests are listed, the counts recompute against them, and the filter is recorded
        $this->assertListed($response, [$flaggedThenFulfilled, $flaggedInProgress]);
        $this->assertKpis($response, ['open' => 1, 'overdue' => 1, 'due_in_7_days' => 0]);
        $this->assertQueueReadAudited($response, ['filters' => ['sla_breached' => 'true'], 'result_count' => 2, 'page' => 1]);
    }

    #[Test]
    #[DataProvider('provideFlags')]
    public function when_a_flag_is_false_then_the_queue_is_not_restricted(string $flag): void
    {
        // Given an unflagged request due in four days and a flagged overdue one
        $dueSoon = $this->aRequestDue($this->company, '2026-10-05 09:00:00');
        $flaggedOverdue = $this->aRequestDue($this->otherCompany, '2026-09-28 09:00:00', 'breached');

        // When the admin lists the queue with this row's flag switched off
        $response = $this->listQueue($this->admin, [$flag => 'false']);

        // Then nothing is filtered out, the counts cover both, and the switched-off flag is recorded
        $this->assertListed($response, [$flaggedOverdue, $dueSoon]);
        $this->assertKpis($response, ['open' => 2, 'overdue' => 1, 'due_in_7_days' => 1]);
        $this->assertQueueReadAudited($response, ['filters' => [$flag => 'false'], 'result_count' => 2, 'page' => 1]);
    }

    #[Test]
    public function when_a_page_is_supplied_outside_the_query_string_then_it_is_ignored(): void
    {
        // Given sixteen requests — two pages at the default size
        $this->sixteenRequests();

        // When the admin sends page 2 in the request body instead of the query string
        $response = $this->listQueueWithBody(['page' => 2]);

        // Then the body is ignored and the first page comes back
        $response->assertOk();
        $this->assertCount(15, $response->json('data'));
        $response->assertJsonPath('meta.pagination.page', 1);
    }

    #[Test]
    public function when_the_queue_spans_pages_then_the_requested_page_is_returned_with_its_own_audit_row(): void
    {
        // Given sixteen requests due on consecutive days from tomorrow
        $requests = $this->sixteenRequests();

        // When the admin asks for the second page of ten
        $response = $this->listQueue($this->admin, ['page' => 2, 'per_page' => 10]);

        // Then the last six are listed, the counts still cover all sixteen, and this page's read is recorded
        $this->assertListed($response, array_slice($requests, 10));
        $response->assertJsonPath('meta.pagination', ['page' => 2, 'per_page' => 10, 'total' => 16, 'last_page' => 2]);
        $this->assertKpis($response, ['open' => 16, 'overdue' => 0, 'due_in_7_days' => 7]);
        $this->assertQueueReadAudited($response, ['filters' => [], 'result_count' => 6, 'page' => 2]);
    }

    #[Test]
    public function when_a_dsar_admin_filter_is_built_without_values_then_defaults_apply(): void
    {
        // Given no query values at all
        // When the filter is built
        $filters = DsarRequestAdminFilters::validateAndCreate([]);

        // Then nothing is filtered, both flags are off, and pagination falls to its defaults
        $this->assertSame(
            [null, null, false, false, null, null],
            [$filters->status, $filters->company_id, $filters->overdueOnly(), $filters->breachedOnly(), $filters->page, $filters->per_page],
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidQueueQueries(): array
    {
        return [
            'unknown sort key' => [['sort' => 'sla_deadline'], 'sort', 'This filter is not supported.'],
            'plausible but unknown status' => [['status' => 'pending'], 'status', 'The selected status is invalid.'],
            'array-valued status' => [['status' => ['in_progress']], 'status', 'The status field must be a string.'],
            'malformed company id' => [['company_id' => 'not-a-uuid'], 'company_id', 'The company id field must be a valid UUID.'],
            'overdue neither true nor false' => [['overdue' => 'yes'], 'overdue', 'The selected overdue is invalid.'],
            'breached as a number' => [['sla_breached' => '1'], 'sla_breached', 'The selected sla breached is invalid.'],
            'per_page below floor' => [['per_page' => 0], 'per_page', 'The per page field must be at least 1.'],
            'per_page above cap' => [['per_page' => 101], 'per_page', 'The per page field must not be greater than 100.'],
            'page below floor' => [['page' => 0], 'page', 'The page field must be at least 1.'],
            'empty status value' => [['status' => ''], 'status', 'The status field must have a value.'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideClosedStates(): array
    {
        return [
            'fulfilled' => ['fulfilled'],
            'rejected' => ['rejected'],
            'cancelled' => ['cancelled'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideFlags(): array
    {
        return [
            'overdue' => ['overdue'],
            'breached' => ['sla_breached'],
        ];
    }

    /**
     * @return array<string, array{string, array{open: int, overdue: int, due_in_7_days: int}}>
     */
    public static function provideDeadlinesAroundToday(): array
    {
        return [
            'a day late' => ['2026-09-30 09:00:00', ['open' => 1, 'overdue' => 1, 'due_in_7_days' => 0]],
            'the last second of today' => ['2026-10-01 23:59:59', ['open' => 1, 'overdue' => 1, 'due_in_7_days' => 0]],
            'the first second of tomorrow' => ['2026-10-02 00:00:00', ['open' => 1, 'overdue' => 0, 'due_in_7_days' => 1]],
            'the last second of the seventh day' => ['2026-10-08 23:59:59', ['open' => 1, 'overdue' => 0, 'due_in_7_days' => 1]],
            'the first second of the eighth day' => ['2026-10-09 00:00:00', ['open' => 1, 'overdue' => 0, 'due_in_7_days' => 0]],
        ];
    }

    /**
     * @return array<string, array{string, array{open: int, overdue: int, due_in_7_days: int}}>
     */
    public static function provideStatusFilters(): array
    {
        $none = ['open' => 0, 'overdue' => 0, 'due_in_7_days' => 0];

        return [
            'in progress' => ['in_progress', ['open' => 1, 'overdue' => 0, 'due_in_7_days' => 0]],
            'fulfilled' => ['fulfilled', $none],
            'rejected' => ['rejected', $none],
            'cancelled' => ['cancelled', $none],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEmptyAdminFilterValues(): array
    {
        return [
            'empty status' => ['status', ''],
            'empty company_id' => ['company_id', ''],
            'empty overdue' => ['overdue', ''],
            'empty sla_breached' => ['sla_breached', ''],
            'empty page' => ['page', ''],
            'empty per_page' => ['per_page', ''],
            'ascii whitespace per_page' => ['per_page', ' '],
            'ascii whitespace company_id' => ['company_id', ' '],
        ];
    }

    /**
     * List the DSAR queue on the platform-admin surface as $caller, or as a
     * guest when null — no company header, the admin family is cross-tenant.
     *
     * @param  array<string, mixed>  $query
     */
    protected function listQueue(?User $caller, array $query = []): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->getJson('/api/v1/admin/dsar-requests?'.http_build_query($query));
    }

    /**
     * A request about a new member of $company, due at $deadline, in $state —
     * a status, or a breach flagged while in progress or before a late fulfilment.
     */
    protected function aRequestDue(Company $company, string $deadline, string $state = 'in_progress'): DsarRequestRecord
    {
        $factory = DsarRequestRecord::factory()
            ->forSubject($company, $this->aCompanyMember($company))
            ->dueAt($deadline);

        $factory = match ($state) {
            'in_progress' => $factory,
            'fulfilled' => $factory->fulfilled(),
            'rejected' => $factory->rejected(),
            'cancelled' => $factory->cancelled(),
            'breached' => $factory->slaBreached(),
            'breached_fulfilled' => $factory->slaBreached()->fulfilled(),
            default => throw new LogicException("No such request state: {$state}"),
        };

        return $factory->create();
    }

    /**
     * The admin row an in-progress $request is listed as — the member fields,
     * its company and its countdown; no timeline (the detail's alone).
     *
     * @return array<string, mixed>
     */
    protected function adminRowOf(DsarRequestRecord $request, int $daysRemaining): array
    {
        return [
            'id' => $request->getKey(),
            'status' => 'in_progress',
            'request_type' => 'access',
            'subject' => ['id' => $request->subject->getKey(), 'name' => $request->subject->name],
            'requested_by' => ['id' => $request->requestedBy->getKey(), 'name' => $request->requestedBy->name],
            'requested_at' => $request->requested_at->toISOString(),
            'sla_deadline' => $request->sla_deadline->toISOString(),
            'completed_at' => null,
            'download_available' => false,
            'download_expires_at' => null,
            'cancelled_at' => null,
            'rejection_reason' => null,
            'company' => ['id' => $request->company->getKey(), 'name' => $request->company->name],
            'days_remaining' => $daysRemaining,
            'sla_breached_at' => null,
            'download_expired_at' => null,
        ];
    }

    /**
     * The page lists exactly $requests, in this order.
     *
     * @param  list<DsarRequestRecord>  $requests
     */
    protected function assertListed(TestResponse $response, array $requests): void
    {
        $response->assertOk();
        $this->assertSame(
            array_map(fn (DsarRequestRecord $request): string => $request->getKey(), $requests),
            array_column($response->json('data'), 'id'),
        );
    }

    /**
     * The KPI strip shows exactly $kpis.
     *
     * @param  array{open: int, overdue: int, due_in_7_days: int}  $kpis
     */
    protected function assertKpis(TestResponse $response, array $kpis): void
    {
        $this->assertSame($kpis, $response->json('kpis'));
    }

    /**
     * Exactly one read of the queue is recorded — platform-level, by the
     * admin, with the request's context and $metadata as its query context.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function assertQueueReadAudited(TestResponse $response, array $metadata): void
    {
        $entry = AuditEntry::query()->where('action', 'dsar_queue_viewed')->sole();

        $this->assertDatabaseHas('audit_trail', [
            'id' => $entry->getKey(),
            'company_id' => null,
            'stored_event_id' => null,
            'event_class' => 'DsarRequestQueueViewed',
            'subject_type' => 'dsar_request_queue',
            'subject_id' => null,
            'subject_label' => null,
            'description' => 'Platform admin viewed a page of the DSAR request queue.',
            'actor_user_id' => $this->admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $this->admin->name,
            'ip_address' => '127.0.0.1',
            'request_id' => $response->headers->get('Correlation-Id'),
        ]);
        $this->assertNotNull($entry->user_agent);
        $this->assertEquals($metadata, $entry->metadata);
    }

    /**
     * The admin lists the queue with $body sent as the request body, the query string empty.
     *
     * @param  array<string, mixed>  $body
     */
    protected function listQueueWithBody(array $body): TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->json('GET', '/api/v1/admin/dsar-requests', $body);
    }

    /**
     * Sixteen in-progress requests due on consecutive days from 2 Oct — one
     * more than a default page.
     *
     * @return list<DsarRequestRecord>
     */
    protected function sixteenRequests(): array
    {
        return array_map(
            fn (int $day): DsarRequestRecord => $this->aRequestDue($this->company, sprintf('2026-10-%02d 09:00:00', $day + 1)),
            range(1, 16),
        );
    }

    /**
     * The admin's queue call with the query log on around it.
     *
     * @return array{TestResponse, int}
     */
    protected function listQueueCountingQueries(): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->listQueue($this->admin);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$response, $queryCount];
    }

    /**
     * No read of the queue was recorded.
     */
    protected function assertNoQueueReadRecorded(): void
    {
        $this->assertDatabaseMissing('audit_trail', ['action' => 'dsar_queue_viewed']);
    }
}
