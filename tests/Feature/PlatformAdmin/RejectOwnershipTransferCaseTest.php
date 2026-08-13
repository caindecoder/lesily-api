<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use RuntimeException;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use App\Companies\Enums\RejectionReasonCode;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Events\TransactionBeginning;
use App\Companies\Enums\OwnershipTransferCaseOutcome;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class RejectOwnershipTransferCaseTest extends OwnershipTransferCasesTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_no_token_is_presented_then_the_reject_request_is_rejected_as_unauthenticated(): void
    {
        // Given no authenticated caller at all
        // When the reject endpoint is called as a guest
        $response = $this->rejectCase(null, (string) Str::uuid7());

        // Then the request is rejected as unauthenticated and nothing is audited
        $this->assertUnauthenticated($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_a_non_allowlisted_caller_attempts_to_reject_a_case_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given tenant Owner Olive and HER OWN company's open case
        $company = Company::factory()->create();
        $owner = $this->aCompanyMember($company, 'owner');
        $company->update(['owner_id' => $owner->getKey()]);
        $case = $this->anOpenCaseFor($company);

        // When she calls the reject endpoint for that case
        $response = $this->rejectCase($owner, $case->getKey());

        // Then the admin surface is never acknowledged and nothing was rejected
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingRejected($case, $owner->getKey());
    }

    #[Test]
    public function when_the_case_id_is_unknown_then_nothing_is_found_and_no_rejection_is_recorded(): void
    {
        // Given an allowlisted admin and no case at all
        // When they reject an unknown case id
        $response = $this->rejectCase($this->admin, (string) Str::uuid7());

        // Then nothing is found and nothing is audited (find-before-audit)
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    #[DataProvider('invalidRejectionProvider')]
    public function when_the_rejection_reason_or_code_is_invalid_then_the_rejection_is_refused(array $payload, string $field): void
    {
        // Given an open case an allowlisted admin could otherwise reject
        $company = Company::factory()->create();
        $case = $this->anOpenCaseFor($company);

        // When the admin submits the invalid rejection
        $response = $this->rejectCase($this->admin, $case->getKey(), $payload);

        // Then the rejection is refused on the offending field and nothing was written
        $this->assertValidationFailed($response, $field);
        $this->assertNothingRejected($case, $company->owner_id);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidRejectionProvider(): array
    {
        return [
            'missing reason' => [['reasonCode' => 'identity_failed'], 'reason'],
            'empty reason' => [['reason' => '', 'reasonCode' => 'identity_failed'], 'reason'],
            'whitespace-only reason' => [['reason' => '   ', 'reasonCode' => 'identity_failed'], 'reason'],
            'overlong reason' => [['reason' => str_repeat('a', 1001), 'reasonCode' => 'identity_failed'], 'reason'],
            'missing reason code' => [['reason' => 'Requester could not evidence the claimed relationship'], 'reasonCode'],
            'unknown reason code' => [['reason' => 'Requester could not evidence the claimed relationship', 'reasonCode' => 'because'], 'reasonCode'],
        ];
    }

    #[Test]
    #[DataProvider('closedCaseProvider')]
    public function when_the_case_is_already_closed_then_the_rejection_is_refused_with_the_current_state_named(OwnershipTransferCaseOutcome $outcome): void
    {
        // Given a case already closed, whichever way it ended
        $company = Company::factory()->create();
        $case = $this->aClosedCaseFor($company, $outcome);

        // When the admin attempts to reject it
        $response = $this->rejectCase($this->admin, $case->getKey());

        // Then the refusal names the closed state and nothing was written
        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'STATE_GUARD_VIOLATION');
        $response->assertJsonPath('message', 'Only an open or verified ownership-transfer case can be rejected; this case is closed.');
        $this->assertCaseUnchanged($case);
        $this->assertNoAuditRecorded();
        $this->assertNoOwnershipMoved($case, $company->owner_id);
    }

    /**
     * @return array<string, array{OwnershipTransferCaseOutcome}>
     */
    public static function closedCaseProvider(): array
    {
        return [
            'closed executed' => [OwnershipTransferCaseOutcome::Executed],
            'closed rejected' => [OwnershipTransferCaseOutcome::Rejected],
        ];
    }

    #[Test]
    public function when_the_audit_write_fails_then_the_whole_rejection_rolls_back(): void
    {
        // Given an open case and an audit write that will fail AFTER its row
        // is inserted (the post-insert oracle)
        $company = Company::factory()->create();
        $case = $this->anOpenCaseFor($company);
        AuditEntry::created(fn () => throw new RuntimeException('induced audit failure'));

        // When the admin rejects the case
        $response = $this->rejectCase($this->admin, $case->getKey());

        // Then the ONE transaction takes back the close, the reason stamp and
        // the already-inserted audit row together
        $this->assertFailedClosed($response);
        $this->assertNothingRejected($case, $company->owner_id);
    }

    /**
     * @param  array{sqlstate: string, code: int, message: string}  $failure
     */
    #[Test]
    #[DataProvider('contentionProvider')]
    public function when_the_rejection_hits_lock_contention_then_it_is_refused_as_a_conflict(array $failure, ApiErrorCode $expected): void
    {
        // Given an open case and a write inside the locked transaction that
        // dies the way the driver reports it
        $company = Company::factory()->create();
        $case = $this->anOpenCaseFor($company);
        AuditEntry::created(fn () => throw $this->syntheticQueryException($failure));

        // When the admin rejects the case
        $response = $this->rejectCase($this->admin, $case->getKey());

        // Then contention answers as a conflict; anything else passes through
        // (render only — the audit-veto row above is the atomicity net)
        $response->assertStatus($expected->status());
        $response->assertJsonPath('error_code', $expected->value);
    }

    #[Test]
    public function when_the_reason_is_exactly_at_the_limit_then_the_rejection_succeeds(): void
    {
        // Given an open case and a reason at exactly the 1000-character bound
        $company = Company::factory()->create();
        $case = $this->anOpenCaseFor($company);
        $reason = str_repeat('a', 1000);

        // When the admin rejects with the at-limit reason
        $response = $this->rejectCase($this->admin, $case->getKey(), [
            'reason' => $reason,
            'reasonCode' => 'other',
        ]);

        // Then the rejection succeeds and the reason is stored verbatim
        $response->assertOk();
        $this->assertCaseClosedRejected($case, $reason, RejectionReasonCode::Other);
    }

    #[Test]
    #[DataProvider('acceptedReasonCodeProvider')]
    public function when_any_accepted_reason_code_is_cited_then_the_rejection_records_it(RejectionReasonCode $code): void
    {
        // Given an open case
        $company = Company::factory()->create();
        $case = $this->anOpenCaseFor($company);

        // When the admin rejects citing this row's code
        $response = $this->rejectCase($this->admin, $case->getKey(), [
            'reason' => 'Recorded under the cited reason code',
            'reasonCode' => $code->value,
        ]);

        // Then the rejection succeeds with the code recorded
        $response->assertOk();
        $this->assertCaseClosedRejected($case, 'Recorded under the cited reason code', $code);
    }

    /**
     * Every accepted reason code, derived from the enum itself — a future
     * case joins the matrix without this provider changing.
     *
     * @return array<string, array{RejectionReasonCode}>
     */
    public static function acceptedReasonCodeProvider(): array
    {
        $rows = [];
        foreach (RejectionReasonCode::cases() as $code) {
            $rows[$code->value] = [$code];
        }

        return $rows;
    }

    #[Test]
    public function when_a_case_is_rejected_then_the_close_decides_under_the_case_row_lock(): void
    {
        // Given an open case
        $company = Company::factory()->create();
        $case = $this->anOpenCaseFor($company);

        // When the rejection runs with every statement's depth recorded
        $capture = $this->recordedQueriesDuring(function () use ($case): void {
            $this->rejectCase($this->admin, $case->getKey())->assertOk();
        });

        // Then the close decision is made against the locked case row
        $this->assertCloseDecidedUnderCaseRowLock($capture);
    }

    #[Test]
    public function when_a_verified_case_is_rejected_then_no_ownership_change_is_persisted(): void
    {
        // Given a VERIFIED case naming live member Alex — everything an
        // execute would need is present, yet reject must move nothing
        $company = Company::factory()->create();
        $alex = $this->aCompanyMember($company, 'employee');
        $case = $this->aVerifiedCaseFor($company, $alex);

        // When the admin rejects it citing target ineligibility
        $response = $this->rejectCase($this->admin, $case->getKey(), [
            'reason' => 'Target is not eligible to assume ownership',
            'reasonCode' => 'target_ineligible',
        ]);

        // Then the case closes rejected with its verification evidence
        // preserved, the rejection is audited, and ownership never moved
        $response->assertOk();
        $this->assertCaseClosedRejected($case, 'Target is not eligible to assume ownership', RejectionReasonCode::TargetIneligible);
        $this->assertRejectionAudited($case, $response);
        $this->assertNoOwnershipMoved($case, $company->owner_id);
    }

    #[Test]
    public function when_an_open_case_is_rejected_then_it_closes_with_the_reason_on_the_record(): void
    {
        // Given Contoso's open case, Owner Olive holding her owner pivot
        $company = Company::factory()->create();
        $olive = User::query()->findOrFail($company->owner_id);
        $company->users()->attach($olive->getKey(), ['role_id' => $this->seededRole('owner')->getKey()]);
        $case = $this->anOpenCaseFor($company);

        // When the admin rejects it for failed identity evidence
        $response = $this->rejectCase($this->admin, $case->getKey());

        // Then the case closes rejected with the reason verbatim on the
        // record, exactly one audit row names the admin, no domain event
        // exists, Olive keeps her seat AND her pivot, and the wire carries the code
        $this->assertCaseClosedRejected($case, 'Requester could not evidence the claimed relationship', RejectionReasonCode::IdentityFailed);
        $this->assertRejectionAudited($case, $response);
        $this->assertNoOwnershipMoved($case, $olive->getKey());
        $olivePivot = CompanyUser::query()
            ->where('company_id', $case->company_id)
            ->where('user_id', $olive->getKey())
            ->sole();
        $this->assertSame($this->seededRole('owner')->getKey(), $olivePivot->role_id);
        $this->assertRejectedCaseOnTheWire($response, $case);
    }

    /**
     * Call the REAL admin reject endpoint (UC-1-128) as $actor, or as a guest
     * when null. The default payload is a valid rejection; rows override or
     * strip keys. Deliberately NO X-Company-Context — the /api/v1/admin/*
     * family is cross-tenant by design (cd-foundation-http §3).
     */
    protected function rejectCase(?User $actor, string $caseId, array $payload = [
        'reason' => 'Requester could not evidence the claimed relationship',
        'reasonCode' => 'identity_failed',
    ]): TestResponse
    {
        if ($actor !== null) {
            Sanctum::actingAs($actor);
        }

        return $this->patchJson("/api/v1/admin/ownership-transfer-cases/{$caseId}/reject", $payload);
    }

    /**
     * Record every SQL statement run during $act with the transaction depth
     * it ran at, plus how many transactions BEGAN during the act (DB::listen
     * + per-statement DB::transactionLevel() — the T2 oracle idiom: SQL text
     * alone cannot see transaction boundaries, and RefreshDatabase's ambient
     * wrapper supplies the depth baseline).
     *
     * @return array{ambient: int, queries: list<array{sql: string, depth: int}>, transactions: int}
     */
    protected function recordedQueriesDuring(callable $act): array
    {
        $ambient = DB::transactionLevel();
        $queries = [];
        $transactionsBegun = 0;

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = [
                'sql' => strtolower($query->sql),
                'depth' => DB::transactionLevel(),
            ];
        });
        Event::listen(TransactionBeginning::class, function () use (&$transactionsBegun): void {
            $transactionsBegun++;
        });

        $act();

        return ['ambient' => $ambient, 'queries' => $queries, 'transactions' => $transactionsBegun];
    }

    /**
     * The close decision was made under the case-row lock, inside ONE
     * transaction: the FIRST read of ownership_transfer_cases is the locked
     * one (no unlocked pre-read may feed the guard), it runs at depth above
     * ambient, the closing update follows it at depth above ambient, and
     * exactly one transaction spans the whole act — a lock released before
     * the write, or a stale unlocked snapshot, fails here.
     */
    protected function assertCloseDecidedUnderCaseRowLock(array $capture): void
    {
        $lockIndex = null;
        $updateIndex = null;

        foreach ($capture['queries'] as $index => $query) {
            $readsCases = str_starts_with($query['sql'], 'select')
                && str_contains($query['sql'], 'from `ownership_transfer_cases`');

            if ($lockIndex === null && $readsCases) {
                $this->assertStringContainsString('for update', $query['sql'], 'The FIRST read of ownership_transfer_cases must be the locked one.');
                $this->assertGreaterThan($capture['ambient'], $query['depth']);
                $lockIndex = $index;
            }

            if ($updateIndex === null && str_starts_with($query['sql'], 'update `ownership_transfer_cases`')) {
                $this->assertGreaterThan($capture['ambient'], $query['depth']);
                $updateIndex = $index;
            }
        }

        $this->assertNotNull($lockIndex, 'No locked read of ownership_transfer_cases was recorded.');
        $this->assertNotNull($updateIndex, 'No closing update of ownership_transfer_cases was recorded.');
        $this->assertGreaterThan($lockIndex, $updateIndex);
        $this->assertSame(1, $capture['transactions'], 'Expected exactly ONE transaction to span the whole rejection.');
    }

    /**
     * The case closed rejected: outcome + the reason stored verbatim beside
     * its code (the enum cast round-trips), the verification evidence
     * preserved exactly as arranged, and the executed columns untouched.
     */
    protected function assertCaseClosedRejected(OwnershipTransferCase $case, string $reason, RejectionReasonCode $code): void
    {
        $fresh = $case->fresh();
        $this->assertInstanceOf(OwnershipTransferCase::class, $fresh);

        $this->assertSame('closed', $fresh->status->value);
        $this->assertSame('rejected', $fresh->closed_outcome?->value);
        $this->assertSame($reason, $fresh->rejection_reason);
        $this->assertSame($code, $fresh->rejection_reason_code);
        $this->assertSame($case->verified_at?->toDateTimeString(), $fresh->verified_at?->toDateTimeString());
        $this->assertSame($case->verified_by, $fresh->verified_by);
        $this->assertNull($fresh->executed_at);
        $this->assertNull($fresh->executed_by);
    }

    /**
     * Exactly one audit row evidences the rejection, in the cd-audit-trail
     * shape: action case_rejected on the case subject, stored_event_id NULL
     * (no domain event exists — nothing about the company changed), actor
     * trio pinned, and the request-context columns IDENTITY-pinned.
     */
    protected function assertRejectionAudited(OwnershipTransferCase $case, TestResponse $response): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'event_class' => 'OwnershipTransferCaseRejected',
            'subject_type' => 'ownership_transfer_case',
            'subject_id' => $case->getKey(),
            'subject_label' => null,
            'action' => 'case_rejected',
            'description' => 'Platform admin rejected an ownership-transfer case.',
            'company_id' => $case->company_id,
            'actor_user_id' => $this->admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $this->admin->name,
            'stored_event_id' => null,
            'metadata' => null,
            'severity' => 'info',
            'ip_address' => '127.0.0.1',
        ]);

        $entry = AuditEntry::query()->sole();
        $this->assertSame($response->headers->get('Correlation-Id'), $entry->request_id);
        $this->assertNotNull($entry->user_agent);
    }

    /**
     * The rejected case on the wire — 200 with the FULL admin contract locked
     * byte-for-byte (12 keys as of T8: + rejection_reason_code; the prose
     * rejection_reason stays OFF the wire, notes/artefacts/event-id parity).
     */
    protected function assertRejectedCaseOnTheWire(TestResponse $response, OwnershipTransferCase $case): void
    {
        $fresh = $case->fresh();
        $this->assertInstanceOf(OwnershipTransferCase::class, $fresh);

        $response->assertOk();
        $response->assertExactJson(['data' => [
            'id' => $fresh->getKey(),
            'company_id' => $fresh->company_id,
            'status' => 'closed',
            'closed_outcome' => 'rejected',
            'rejection_reason_code' => 'identity_failed',
            'new_owner_user_id' => $fresh->new_owner_user_id,
            'requested_context' => $fresh->requested_context,
            'verified_at' => null,
            'verified_by' => null,
            'executed_at' => null,
            'executed_by' => null,
            'created_at' => $fresh->created_at->toISOString(),
        ]]);
    }

    /**
     * No ownership change was persisted: NO domain event of ANY class exists
     * on ANY stream (the plain-write invariant — fixtures never record
     * events, so zero is exact), the owner column still names $ownerUserId,
     * nobody else holds an owner-role pivot, and no membership pivot of the
     * company was soft-deleted by the rejection.
     */
    protected function assertNoOwnershipMoved(OwnershipTransferCase $case, string $ownerUserId): void
    {
        $this->assertSame(0, LesilyStoredEvent::query()->count());

        $this->assertSame($ownerUserId, Company::query()->whereKey($case->company_id)->sole()->owner_id);

        $this->assertSame(0, CompanyUser::query()
            ->where('company_id', $case->company_id)
            ->where('role_id', $this->seededRole('owner')->getKey())
            ->where('user_id', '!=', $ownerUserId)
            ->withTrashed()
            ->count());

        $this->assertSame(0, CompanyUser::query()
            ->where('company_id', $case->company_id)
            ->onlyTrashed()
            ->count());
    }

    /**
     * Nothing was rejected — the case row is exactly as arranged with its
     * rejection columns still empty, no domain event was recorded, no audit
     * row exists, the company's owner column still names $ownerUserId, and
     * nobody else holds an owner-role pivot (a rejection must never move
     * ownership on ANY path — the UC's Minimal Guarantee).
     */
    protected function assertNothingRejected(OwnershipTransferCase $case, string $ownerUserId): void
    {
        $this->assertCaseUnchanged($case);

        $fresh = $case->fresh();
        $this->assertInstanceOf(OwnershipTransferCase::class, $fresh);
        $this->assertNull($fresh->rejection_reason);
        $this->assertNull($fresh->rejection_reason_code);

        $this->assertNoAuditRecorded();

        $this->assertNoOwnershipMoved($case, $ownerUserId);
    }
}
