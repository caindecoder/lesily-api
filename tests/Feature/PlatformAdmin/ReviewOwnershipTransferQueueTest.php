<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;

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
        $this->assertQueueReadAudited($response, ['filters' => [], 'result_count' => 0, 'page' => 1]);
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

        // When the admin drills into one tenant
        $response = $this->getAdminQueue($admin, ['company_id' => $wanted->getKey()]);

        // Then only that tenant's transfer is listed
        $response->assertOk();
        $this->assertQueueOrder($response, [$mine->getKey()]);
    }

    /**
     * @return array<string, array{OwnershipTransferStatus}>
     */
    public static function provideTerminalStates(): array
    {
        return [
            'executed' => [OwnershipTransferStatus::Executed],
            'rejected' => [OwnershipTransferStatus::Rejected],
            'cancelled' => [OwnershipTransferStatus::Cancelled],
            'expired' => [OwnershipTransferStatus::Expired],
        ];
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
            'company filter is not a uuid' => [['company_id' => 'not-a-uuid'], 'company_id'],
            'per_page over the ceiling' => [['per_page' => 101], 'per_page'],
            'per_page below one' => [['per_page' => 0], 'per_page'],
            'page below one' => [['page' => 0], 'page'],
        ];
    }
}
