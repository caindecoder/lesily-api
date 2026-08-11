<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Companies\Entities\OwnershipTransferCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Companies\Enums\OwnershipTransferCaseOutcome;
use App\Companies\Events\CompanyOwnershipTransferred;
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

        // Then the refusal names the closed state and the case is unchanged
        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'STATE_GUARD_VIOLATION');
        $response->assertJsonPath('message', 'Only an open or verified ownership-transfer case can be rejected; this case is closed.');
        $this->assertCaseUnchanged($case);
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
     * it ran at (DB::listen + per-statement DB::transactionLevel() — the T2
     * oracle idiom: SQL text alone cannot see transaction boundaries, and
     * RefreshDatabase's ambient wrapper supplies the depth baseline).
     *
     * @return array{ambient: int, queries: list<array{sql: string, depth: int}>}
     */
    protected function recordedQueriesDuring(callable $act): array
    {
        $ambient = DB::transactionLevel();
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = [
                'sql' => strtolower($query->sql),
                'depth' => DB::transactionLevel(),
            ];
        });

        $act();

        return ['ambient' => $ambient, 'queries' => $queries];
    }

    /**
     * The close decision was made under the case-row lock: a select … for
     * update on ownership_transfer_cases at depth above ambient, with the
     * closing update after that locked read — reject is the case row's
     * second writer beside execute, and the lock is the race's arbiter.
     */
    protected function assertCloseDecidedUnderCaseRowLock(array $capture): void
    {
        $lockIndex = null;
        $updateIndex = null;

        foreach ($capture['queries'] as $index => $query) {
            if ($lockIndex === null
                && str_contains($query['sql'], 'from `ownership_transfer_cases`')
                && str_contains($query['sql'], 'for update')) {
                $this->assertGreaterThan($capture['ambient'], $query['depth']);
                $lockIndex = $index;
            }

            if ($updateIndex === null && str_starts_with($query['sql'], 'update `ownership_transfer_cases`')) {
                $updateIndex = $index;
            }
        }

        $this->assertNotNull($lockIndex, 'No locked read of ownership_transfer_cases was recorded.');
        $this->assertNotNull($updateIndex, 'No closing update of ownership_transfer_cases was recorded.');
        $this->assertGreaterThan($lockIndex, $updateIndex);
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

        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $case->company_id)
            ->where('event_class', CompanyOwnershipTransferred::class)
            ->count());

        $this->assertNoAuditRecorded();

        $this->assertSame($ownerUserId, Company::query()->whereKey($case->company_id)->sole()->owner_id);

        $this->assertSame(0, CompanyUser::query()
            ->where('company_id', $case->company_id)
            ->where('role_id', $this->seededRole('owner')->getKey())
            ->where('user_id', '!=', $ownerUserId)
            ->withTrashed()
            ->count());
    }
}
