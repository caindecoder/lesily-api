<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\UseCases\Commands\OwnershipTransferAdminFilters;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;

/**
 * UC-1-127 — Review Ownership Transfer Queue (slice #11 Task 3): the platform
 * admin's cross-tenant triage queue at GET /api/v1/admin/ownership-transfers
 * (auth:sanctum + platform.admin, deliberately NO auth.company), ordered
 * soonest-deadline-first (F1), defaulting to the open states (F5), and
 * recording ONE platform-level audit_trail row per read (F3).
 */
class ReviewOwnershipTransferQueueTest extends OwnershipTransferAdminTestCase
{
    // --- Sad ---

    #[Test]
    public function when_no_token_is_presented_then_the_request_is_rejected_as_unauthenticated(): void
    {
        // Given nobody is authenticated

        // When the admin queue is requested as a guest
        $response = $this->getAdminQueue(null);

        // Then the request is rejected as unauthenticated and no read is recorded
        $this->assertUnauthenticated($response);
        $this->assertNoAuditRecorded();
    }

    // Sad — the admin surface never acknowledges its existence to tenant-side
    // callers: every seeded tenant role, even one whose OWN company holds a
    // transfer, gets the byte-identical generic 404 and the read is never
    // recorded (UC-1-127 error-flow 2a + Gherkin sc.2/sc.4).
    #[Test]
    #[DataProvider('provideTenantRoles')]
    public function when_a_tenant_role_calls_the_admin_queue_then_an_indistinguishable_not_found_is_returned(string $roleCode): void
    {
        // Given a tenant member whose own company holds a transfer, while the allowlist names a different user
        [$company, $member] = $this->aCompanyWithMember($roleCode);
        $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);
        $this->allowlist([(string) Str::uuid()]);

        // When that member calls the admin queue
        $response = $this->getAdminQueue($member);

        // Then the response is the indistinguishable generic not-found and no read is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }

    // Sad — the query whitelist refuses anything it does not model, and the read
    // never happens, so nothing is recorded (UC-1-127 error-flows 4h/4i).
    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideInvalidQueryInputs')]
    public function when_the_query_input_is_invalid_then_it_is_rejected(array $query, string $invalidField): void
    {
        // Given an allowlisted platform admin
        $admin = $this->anAllowlistedAdmin();

        // When they call the queue with an unsupported query parameter
        $response = $this->getAdminQueue($admin, $query);

        // Then it is refused as shape-invalid on the offending field and no read is recorded
        $this->assertValidationRejected($response, $invalidField);
        $this->assertNoAuditRecorded();
    }

    // --- Edge ---

    // Edge — a quiet queue still records the access: the read happened, even
    // though it returned nothing (UC-1-127 alt-flow 4f).
    #[Test]
    public function when_no_transfers_are_open_then_the_queue_is_empty_and_the_read_is_still_recorded(): void
    {
        // Given an allowlisted platform admin and no transfers anywhere
        $admin = $this->anAllowlistedAdmin();

        // When they open the queue
        $response = $this->getAdminQueue($admin);

        // Then the envelope is empty with a zero total, and the read is still recorded
        $response->assertOk();
        $response->assertExactJson([
            'data' => [],
            'meta' => ['pagination' => ['page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1]],
        ]);
        $this->assertQueueReadAudited($response, $admin, ['filters' => [], 'result_count' => 0, 'page' => 1]);
    }

    // Edge — cross-tenant is the whole point: transfers belonging to companies
    // the admin has no membership in are all visible on one page, because the
    // queue query carries no company predicate (UC-1-127 Tenant Isolation).
    #[Test]
    public function when_transfers_exist_across_tenants_then_every_company_is_visible(): void
    {
        // Given two unrelated companies each holding an open transfer
        $admin = $this->anAllowlistedAdmin();
        [$companyA] = $this->aCompanyWithMember('owner');
        [$companyB] = $this->aCompanyWithMember('owner');
        $inA = $this->aTransferInState($companyA, User::factory()->create(), OwnershipTransferStatus::Requested);
        $inB = $this->aTransferInState($companyB, User::factory()->create(), OwnershipTransferStatus::Requested);

        // When the admin opens the queue
        $response = $this->getAdminQueue($admin);

        // Then both tenants' transfers are on the page — canonicalizing, because
        // this test owns VISIBILITY; ordering is #10's assertion
        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            [$inA->getKey(), $inB->getKey()],
            $response->collect('data')->pluck('id')->all(),
        );
    }

    // Edge — the queue is a WORK queue: with no ?state= it shows only the two
    // open states, so terminal history never buries actionable items (F5). The
    // terminals stay reachable by asking for them explicitly (alt-flow 4d).
    #[Test]
    public function when_the_state_filter_is_omitted_then_only_open_transfers_are_listed(): void
    {
        // Given one company holding a transfer in every one of the six states
        $admin = $this->anAllowlistedAdmin();
        [$company] = $this->aCompanyWithMember('owner');
        $target = User::factory()->create();
        $open = [];
        foreach (OwnershipTransferStatus::cases() as $state) {
            $transfer = $this->aTransferInState($company, $target, $state);
            if ($state->isOpen()) {
                $open[] = $transfer->getKey();
            }
        }

        // When the admin opens the queue with no state filter
        $response = $this->getAdminQueue($admin);

        // Then only the open (requested + verified) rows are listed
        $response->assertOk();
        $this->assertEqualsCanonicalizing($open, $response->collect('data')->pluck('id')->all());
    }

    // Edge — terminal states are excluded from the default set but remain
    // readable on request, which is what makes the queue usable for audit
    // lookback (UC-1-127 alt-flow 4d).
    #[Test]
    #[DataProvider('provideTerminalStates')]
    public function when_a_terminal_state_is_requested_then_those_rows_are_returned_for_lookback(OwnershipTransferStatus $state): void
    {
        // Given a company holding one transfer in the terminal state under test and one still open
        $admin = $this->anAllowlistedAdmin();
        [$company] = $this->aCompanyWithMember('owner');
        $target = User::factory()->create();
        $terminal = $this->aTransferInState($company, $target, $state);
        $this->aTransferInState($company, $target, OwnershipTransferStatus::Requested);

        // When the admin asks for that terminal state explicitly
        $response = $this->getAdminQueue($admin, ['state' => $state->value]);

        // Then only the terminal row is returned
        $response->assertOk();
        $this->assertQueueOrder($response, [$terminal->getKey()]);
    }

    // Edge — terminal rows all carry a NULL deadline, so their ordering falls
    // through to the id tie-break. Pinned with three rows because a one-row
    // assertion cannot see ordering at all, and because "it happens to come out
    // oldest-first" is currently an accident of UUIDv7 being time-ordered rather
    // than a stated contract.
    #[Test]
    public function when_several_terminal_rows_are_requested_then_the_id_tie_break_orders_them(): void
    {
        // Given three rejected transfers for one company
        $admin = $this->anAllowlistedAdmin();
        [$company] = $this->aCompanyWithMember('owner');
        $target = User::factory()->create();
        $rejected = collect(range(1, 3))
            ->map(fn (): string => $this->aTransferInState($company, $target, OwnershipTransferStatus::Rejected)->getKey())
            ->all();

        // When the admin asks for the rejected bucket
        $response = $this->getAdminQueue($admin, ['state' => 'rejected']);

        // Then all three come back in ascending id order
        $response->assertOk();
        $expected = collect($rejected)->sort()->values()->all();
        $this->assertQueueOrder($response, array_values($expected));
    }

    // Edge — the single-tenant drill-down an admin uses while working a support
    // ticket: ?company_id= narrows the cross-tenant queue to one tenant
    // (UC-1-127 alt-flow 4e). It is a FILTER, not a scope - the read stays
    // cross-tenant by default.
    #[Test]
    public function when_a_company_filter_is_applied_then_only_that_tenants_transfers_are_listed(): void
    {
        // Given two companies each holding an open transfer
        $admin = $this->anAllowlistedAdmin();
        [$wanted] = $this->aCompanyWithMember('owner');
        [$other] = $this->aCompanyWithMember('owner');
        $mine = $this->aTransferInState($wanted, User::factory()->create(), OwnershipTransferStatus::Requested);
        $this->aTransferInState($other, User::factory()->create(), OwnershipTransferStatus::Requested);

        // ...and that tenant also has a terminal transfer in its history
        $this->aTransferInState($wanted, User::factory()->create(), OwnershipTransferStatus::Executed);

        // When the admin drills into one tenant
        $response = $this->getAdminQueue($admin, ['company_id' => $wanted->getKey()]);

        // Then only that tenant's OPEN transfer is listed. Recorded divergence:
        // UC alt-flow 4e says the drill-down shows "all states", but company_id
        // composes with the open-states default (F5) rather than overriding it,
        // so terminal history needs an explicit ?state=. Deliberate — pinned here
        // so it is a decision rather than an accident.
        $response->assertOk();
        $this->assertQueueOrder($response, [$mine->getKey()]);
    }

    // Edge — the §21 N+1 mandate has teeth on the admin queue too: the page
    // resolves both user relations for every row without a per-row query.
    #[Test]
    public function when_the_queue_is_paginated_then_the_user_relations_are_eager_loaded(): void
    {
        // Given a page of transfers larger than a bounded query count
        [$company] = $this->aCompanyWithMember('owner');
        $rows = 8;
        OwnershipTransferRequest::factory()->count($rows)->requested()->create([
            'company_id' => $company->getKey(),
            'current_owner_user_id' => $company->owner_id,
            'target_user_id' => User::factory()->create()->getKey(),
        ]);

        // When the repository paginates and every row's user relations are read
        DB::enableQueryLog();
        $page = app(OwnershipTransferRequestRepositoryInterface::class)
            ->paginateForAdminQueue(OwnershipTransferAdminFilters::validateAndCreate([]));
        $relations = collect($page->items())
            ->flatMap(fn (OwnershipTransferRequest $transfer) => [
                $transfer->targetUser,
                $transfer->currentOwner,
                // company too: the admin resource reads it for the tenant summary,
                // so dropping it from the eager-load degrades silently to N extra
                // queries on the one page that is by definition cross-tenant.
                $transfer->company,
            ]);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Then all three relations resolved for every row, in fewer queries than
        // there are rows — eager-loaded, not fetched per row. Lazy loading would
        // fire ~3 per row and exceed the row count.
        $this->assertCount($rows * 3, $relations->filter());
        $this->assertLessThan($rows, $queryCount);
    }

    // --- Happy ---

    // Happy — the queue is ranked by urgency, not by age (F1): rows come back
    // soonest-SLA-deadline first. The fixture deliberately MIXES state buckets,
    // because within one bucket a deadline is just requested_at plus a constant
    // and pure FIFO would pass too. Here the verified row - youngest by
    // requested_at, but on the 14-day timer - must outrank both older requested
    // rows on the 30-day timer.
    #[Test]
    public function when_the_admin_opens_the_queue_then_rows_are_ordered_by_soonest_deadline_first(): void
    {
        // Given three open transfers across two tenants with staggered deadlines
        $admin = $this->anAllowlistedAdmin();
        [$companyA] = $this->aCompanyWithMember('owner');
        [$companyB] = $this->aCompanyWithMember('owner');
        // An ALREADY-OVERDUE row leads: sc.1's headline was "overdue items ranked
        // first", which a pure ascending deadline subsumes (a past deadline sorts
        // smallest) — but only if a past deadline is actually exercised.
        $overdue = $this->aTransferDueAt($companyA, OwnershipTransferStatus::Requested, CarbonImmutable::now()->subDays(3));
        $dueIn5Days = $this->aTransferDueAt($companyA, OwnershipTransferStatus::Requested, CarbonImmutable::now()->addDays(5));
        $dueIn2Days = $this->aTransferDueAt($companyB, OwnershipTransferStatus::Verified, CarbonImmutable::now()->addDays(2));

        // When the admin opens the queue
        $response = $this->getAdminQueue($admin);

        // Then the most time-pressed transfer leads, regardless of state or age
        $response->assertOk();
        $this->assertQueueOrder($response, [
            $overdue->getKey(),
            $dueIn2Days->getKey(),
            $dueIn5Days->getKey(),
        ]);
    }

    // Happy — a backlog spans pages and the envelope reports the requested one.
    #[Test]
    public function when_the_queue_spans_multiple_pages_then_pagination_reflects_the_requested_page(): void
    {
        // Given more open transfers than the 15-row default page holds
        $admin = $this->anAllowlistedAdmin();
        [$company] = $this->aCompanyWithMember('owner');
        OwnershipTransferRequest::factory()->count(20)->requested()->create([
            'company_id' => $company->getKey(),
            'current_owner_user_id' => $company->owner_id,
            'target_user_id' => User::factory()->create()->getKey(),
        ]);

        // When the admin requests the second page
        $response = $this->getAdminQueue($admin, ['page' => 2]);

        // Then the meta reflects page 2, only the 5-row remainder is returned, and
        // the audit row records the page actually served. result_count is 5 while
        // total is 20 — the one arrangement in the suite where the two differ, so
        // it is what stops result_count silently becoming total().
        $response->assertOk();
        $this->assertSame(
            ['page' => 2, 'per_page' => 15, 'total' => 20, 'last_page' => 2],
            $response->json('meta.pagination'),
        );
        $this->assertCount(5, $response->json('data'));
        $this->assertQueueReadAudited($response, $admin, [
            'filters' => ['page' => 2],
            'result_count' => 5,
            'page' => 2,
        ]);
    }

    // Happy — a requested page size is honoured end to end and lands on the
    // audit row; without this the endpoint could ignore ?per_page= entirely and
    // only the rejection cases would notice.
    #[Test]
    public function when_a_page_size_is_requested_then_it_is_honored(): void
    {
        // Given five open transfers
        $admin = $this->anAllowlistedAdmin();
        [$company] = $this->aCompanyWithMember('owner');
        OwnershipTransferRequest::factory()->count(5)->requested()->create([
            'company_id' => $company->getKey(),
            'current_owner_user_id' => $company->owner_id,
            'target_user_id' => User::factory()->create()->getKey(),
        ]);

        // When the admin asks for a page size of two
        $response = $this->getAdminQueue($admin, ['per_page' => 2]);

        // Then exactly two rows return, the meta echoes the size, and it is audited
        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(
            ['page' => 1, 'per_page' => 2, 'total' => 5, 'last_page' => 3],
            $response->json('meta.pagination'),
        );
        $this->assertQueueReadAudited($response, $admin, [
            'filters' => ['per_page' => 2],
            'result_count' => 2,
            'page' => 1,
        ]);
    }

    // Edge — oversized client-controlled headers must not break a valid read.
    // MySQL strict mode turns an over-length insert into SQLSTATE 22001, which
    // the bootstrap catch-all renders as a 500 AFTER the read already succeeded,
    // with no audit row written (the s137 I-1 ruling).
    #[Test]
    public function when_the_request_context_values_are_oversized_then_the_read_still_succeeds(): void
    {
        // Given an allowlisted admin whose client sends 300-char UA and correlation headers
        $admin = $this->anAllowlistedAdmin();

        // When the admin opens the queue
        $response = $this->getAdminQueue($admin, [], [
            'User-Agent' => str_repeat('a', 300),
            'Correlation-Id' => str_repeat('b', 300),
        ]);

        // Then the read succeeds and the audit row stores bounded values
        $response->assertOk();
        $this->assertDatabaseCount('audit_trail', 1);
        $entry = AuditEntry::query()->sole();
        $this->assertLessThanOrEqual(255, strlen((string) $entry->user_agent));
        $this->assertLessThanOrEqual(255, strlen((string) $entry->request_id));
    }

    // Edge — an open transfer with no deadline sorts LAST, not first. MySQL puts
    // NULL first on ASC, so without the NULLS-LAST clause a timer-less row would
    // head the queue ahead of genuinely urgent work.
    #[Test]
    public function when_an_open_transfer_has_no_deadline_then_it_sorts_after_the_timed_ones(): void
    {
        // Given one transfer due in five days and one open transfer with no timer
        $admin = $this->anAllowlistedAdmin();
        [$companyA] = $this->aCompanyWithMember('owner');
        [$companyB] = $this->aCompanyWithMember('owner');
        $timed = $this->aTransferDueAt($companyA, OwnershipTransferStatus::Requested, CarbonImmutable::now()->addDays(5));
        $untimed = $this->aTransferInState($companyB, User::factory()->create(), OwnershipTransferStatus::Verified);
        $untimed->forceFill(['next_state_transition_at' => null])->save();

        // When the admin opens the queue
        $response = $this->getAdminQueue($admin);

        // Then the timed row leads and the timer-less one trails
        $response->assertOk();
        $this->assertQueueOrder($response, [$timed->getKey(), $untimed->getKey()]);
    }

    // Edge — a tenant with no plan renders company.plan as null: the resource's
    // null-safe is load-bearing, because a trial-expired tenant carries a NULL
    // plan and is exactly the kind of company an admin triages a transfer for.
    #[Test]
    public function when_the_tenant_has_no_plan_then_the_company_summary_renders_it_as_null(): void
    {
        // Given a transfer belonging to a company with no plan
        $admin = $this->anAllowlistedAdmin();
        $company = Company::factory()->withoutPlan()->create();
        $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);

        // When the admin opens the queue
        $response = $this->getAdminQueue($admin);

        // Then the company summary carries a null plan (not a 500, not a missing key)
        $response->assertOk();
        $response->assertJsonPath('data.0.company.plan', null);
    }

    // Happy — the read is recorded with its query context: one platform-level
    // row carrying the filters actually applied, the row count, and the page
    // (UC-1-127 step 8 — one entry per page, never one per row).
    #[Test]
    public function when_the_admin_opens_the_queue_then_the_read_is_recorded_as_a_platform_level_audit_entry(): void
    {
        // Given two open transfers, one of them verified
        $admin = $this->anAllowlistedAdmin();
        [$company] = $this->aCompanyWithMember('owner');
        $target = User::factory()->create();
        $this->aTransferInState($company, $target, OwnershipTransferStatus::Verified);
        $this->aTransferInState($company, $target, OwnershipTransferStatus::Requested);

        // When the admin filters the queue to the verified bucket
        $response = $this->getAdminQueue($admin, ['state' => 'verified']);

        // Then one platform-level row records the filters, the count and the page
        $response->assertOk();
        $this->assertQueueReadAudited($response, $admin, [
            'filters' => ['state' => 'verified'],
            'result_count' => 1,
            'page' => 1,
        ]);
    }

    // Happy — the forensic admin row: every designed key present, the target's
    // email UNMASKED (the owner resource masks it), and the verifier material
    // the owner resource withholds actually on the wire.
    #[Test]
    public function when_the_admin_opens_the_queue_then_each_row_carries_exactly_the_admin_shape(): void
    {
        // Given a verified transfer with a known target
        $admin = $this->anAllowlistedAdmin();
        [$company] = $this->aCompanyWithMember('owner');
        $target = User::factory()->create(['name' => 'Alex Turner', 'email' => 'alex.turner@contoso.test']);
        $this->aTransferInState($company, $target, OwnershipTransferStatus::Verified);

        // When the admin opens the queue
        $response = $this->getAdminQueue($admin);

        // Then the row is exactly the admin shape, with nothing masked
        $response->assertOk();
        $row = $response->json('data.0');
        $this->assertSame([
            'id', 'company_id', 'company', 'status', 'request_channel', 'reason',
            'requested_at', 'requested_by', 'target_user',
            'verified_at', 'verified_by', 'verification_notes', 'verification_artefacts',
            'executed_at', 'executed_by', 'old_owner_user_id', 'new_owner_user_id',
            'rejected_at', 'rejected_by', 'rejection_reason', 'rejection_source',
            'cancelled_at', 'cancelled_by', 'cancellation_source', 'cancellation_reason',
            'expired_at', 'expiry_source', 'expired_after_days',
            'next_state_transition_at',
        ], array_keys($row));
        $this->assertSame(
            ['id' => $target->getKey(), 'name' => 'Alex Turner', 'email' => 'alex.turner@contoso.test'],
            $row['target_user'],
        );
        $this->assertSame('Email confirmation + ID document verified.', $row['verification_notes']);
        $this->assertSame($company->name, $row['company']['name']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidQueryInputs(): array
    {
        return [
            'unknown filter key' => [['foo' => 'bar'], 'foo'],
            'old status param name' => [['status' => 'requested'], 'status'],
            // ?sort= is deliberately unsupported on the admin queue: ordering is
            // hard-coded soonest-deadline-first (F1), the LD-idiomatic shape.
            'sort is not a queue parameter' => [['sort' => 'requested_at'], 'sort'],
            'non-canonical state' => [['state' => 'completed'], 'state'],
            // The overdue filter was cut as YAGNI (F2). Pinned so a future
            // contributor cannot add it to the whitelist without implementing
            // it and end up with a silently-ignored filter.
            'overdue filter was cut' => [['overdue' => 'true'], 'overdue'],
            // The UC body's own vocabulary predates the canonical enum re-pin;
            // these are the first two values a client written against the UC
            // text would send, and both are correctly refused.
            'UC-text state alias' => [['state' => 'pending-verification'], 'state'],
            'all is not a state' => [['state' => 'all'], 'state'],
            'company filter is not a uuid' => [['company_id' => 'not-a-uuid'], 'company_id'],
            'per_page over the ceiling' => [['per_page' => 101], 'per_page'],
            'per_page below one' => [['per_page' => 0], 'per_page'],
            'page below one' => [['page' => 0], 'page'],
        ];
    }
}
