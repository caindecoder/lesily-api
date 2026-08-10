<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use RuntimeException;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Companies\Enums\OwnershipTransferCaseOutcome;
use App\Companies\Events\CompanyOwnershipTransferred;
use App\Companies\UseCases\Commands\TransferCompanyOwnershipCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\UseCases\Interactors\TransferCompanyOwnershipInteractor;

class ExecuteOwnershipTransferCaseTest extends OwnershipTransferCasesTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_no_token_is_presented_then_the_execute_request_is_rejected_as_unauthenticated(): void
    {
        // Given no authenticated caller at all
        // When the execute endpoint is called as a guest
        $response = $this->executeCase(null, (string) Str::uuid7());

        // Then the request is rejected as unauthenticated and nothing is audited
        $this->assertUnauthenticated($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_a_non_allowlisted_caller_attempts_to_execute_a_case_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given tenant Owner Olive and HER OWN company's verified case
        $company = Company::factory()->create();
        $owner = $this->aCompanyMember($company, 'owner');
        $case = $this->aVerifiedCaseFor($company);

        // When she calls the execute endpoint for that case
        $response = $this->executeCase($owner, $case->getKey());

        // Then the admin surface is never acknowledged and nothing moved
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingExecuted($case, $company->owner_id);
    }

    #[Test]
    public function when_the_case_id_is_unknown_then_nothing_is_found_and_no_execution_is_recorded(): void
    {
        // Given an allowlisted admin and no case at all
        // When they execute an unknown case id
        $response = $this->executeCase($this->admin, (string) Str::uuid7());

        // Then nothing is found and nothing is audited (find-before-audit)
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNoAuditRecorded();
    }

    #[Test]
    public function when_the_idempotency_key_is_missing_then_the_execution_is_refused(): void
    {
        // Given a verified case an allowlisted admin could otherwise execute
        $company = Company::factory()->create();
        $case = $this->aVerifiedCaseFor($company);

        // When the execute is submitted without an Idempotency-Key
        $response = $this->executeCaseWithoutIdempotencyKey($this->admin, $case->getKey());

        // Then the execution is refused as missing its key and nothing moved
        $this->assertRefusedAsMissingIdempotencyKey($response);
        $this->assertNothingExecuted($case, $company->owner_id);
    }

    #[Test]
    #[DataProvider('nonVerifiedCaseProvider')]
    public function when_the_case_is_not_verified_then_the_execution_is_refused_with_the_current_state_named(string $fixture, string $stateName): void
    {
        // Given a case that is not verified
        $company = Company::factory()->create();
        $case = match ($fixture) {
            'open' => $this->anOpenCaseFor($company),
            'closedExecuted' => $this->aClosedCaseFor($company, OwnershipTransferCaseOutcome::Executed),
            'closedRejected' => $this->aClosedCaseFor($company, OwnershipTransferCaseOutcome::Rejected),
            default => $this->fail('Unknown case fixture token: '.$fixture),
        };

        // When the admin attempts to execute it
        $response = $this->executeCase($this->admin, $case->getKey());

        // Then the refusal names the case's current state and nothing moved
        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'STATE_GUARD_VIOLATION');
        $response->assertJsonPath('message', 'Only a verified ownership-transfer case can be executed; this case is '.$stateName.'.');
        $this->assertNothingExecuted($case, $company->owner_id);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nonVerifiedCaseProvider(): array
    {
        return [
            'still open' => ['open', 'open'],
            'closed executed' => ['closedExecuted', 'closed'],
            'closed rejected' => ['closedRejected', 'closed'],
        ];
    }

    #[Test]
    public function when_the_case_is_unverified_and_the_member_is_gone_then_the_state_refusal_wins(): void
    {
        // Given an OPEN case whose proposed owner's membership was since removed
        $company = Company::factory()->create();
        $gone = $this->aCompanyMember($company, 'employee');
        $case = $this->anOpenCaseFor($company, $gone);
        $company->users()->updateExistingPivot($gone->getKey(), ['deleted_at' => now()]);

        // When the admin attempts to execute it
        $response = $this->executeCase($this->admin, $case->getKey());

        // Then the refusal is the STATE guard's, asked before any cross-entity
        // work — never the member guard's (the 422-where-409 leak, P14)
        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Only a verified ownership-transfer case can be executed; this case is open.');
        $this->assertNothingExecuted($case, $company->owner_id);
    }

    #[Test]
    public function when_the_recorded_new_owner_is_no_longer_an_active_member_then_the_execution_is_refused(): void
    {
        // Given a verified case naming Alex, whose membership was since removed
        $company = Company::factory()->create();
        $alex = $this->aCompanyMember($company, 'employee');
        $case = $this->aVerifiedCaseFor($company, $alex);
        $company->users()->updateExistingPivot($alex->getKey(), ['deleted_at' => now()]);

        // When the admin executes the case
        $response = $this->executeCase($this->admin, $case->getKey());

        // Then the execution is refused on the vanished member and the case stays verified
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'TARGET_NOT_MEMBER');
        $this->assertNothingExecuted($case, $company->owner_id);
    }

    #[Test]
    public function when_ownership_moved_after_the_case_was_opened_then_the_execution_loses_under_the_row_lock(): void
    {
        // Given a verified case whose recorded owner has since been replaced
        $company = Company::factory()->create();
        $alex = $this->aCompanyMember($company, 'employee');
        $case = $this->aVerifiedCaseFor($company, $alex);
        $bob = $this->aCompanyMember($company, 'admin');
        $company->update(['owner_id' => $bob->getKey()]);

        // When the admin executes the case
        $response = $this->executeCase($this->admin, $case->getKey());

        // Then the world has moved — the execution is refused and the case stays verified
        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'STATE_GUARD_VIOLATION');
        $this->assertNothingExecuted($case, $bob->getKey());
    }

    #[Test]
    public function when_the_audit_write_fails_then_the_whole_execution_rolls_back(): void
    {
        // Given a verified case, a live incoming member, and an audit write
        // that will fail AFTER its row is inserted (the post-insert oracle)
        $company = Company::factory()->create();
        $alex = $this->aCompanyMember($company, 'employee');
        $case = $this->aVerifiedCaseFor($company, $alex);
        AuditEntry::created(fn () => throw new RuntimeException('induced audit failure'));

        // When the admin executes the case
        $response = $this->executeCase($this->admin, $case->getKey());

        // Then the ONE transaction takes back the swap, the event, the close
        // and the already-inserted audit row together
        $this->assertReadFailedSafely($response);
        $this->assertNothingExecuted($case, $company->owner_id);
    }

    #[Test]
    public function when_the_verified_case_names_the_current_owner_then_the_execution_is_refused_as_a_self_transfer(): void
    {
        // Given a verified case whose incoming owner IS the company's live owner
        // (verification legally permits it — the owner is a live member)
        $company = Company::factory()->create();
        $owner = User::query()->findOrFail($company->owner_id);
        $case = $this->aVerifiedCaseFor($company, $owner);

        // When the admin executes the case
        $response = $this->executeCase($this->admin, $case->getKey());

        // Then the generalized self-guard refuses — an owner cannot succeed themselves
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'SELF_TRANSFER_BLOCKED');
        $this->assertNothingExecuted($case, $company->owner_id);
    }

    #[Test]
    public function when_the_incoming_owner_has_no_emergency_contact_then_the_execution_still_completes(): void
    {
        // Given a verified case whose incoming owner has NO emergency contact
        // on their membership (the §10b mandate flags-and-prompts, never blocks)
        $company = Company::factory()->create();
        $alex = $this->aCompanyMember($company, 'employee');
        $case = $this->aVerifiedCaseFor($company, $alex);

        // When the admin executes the case
        $response = $this->executeCase($this->admin, $case->getKey());

        // Then the execution completes — the missing contact never blocks it
        $response->assertOk();
        $this->assertSame('closed', $case->fresh()->status->value);
        $this->assertSame($alex->getKey(), Company::query()->whereKey($case->company_id)->sole()->owner_id);
    }

    #[Test]
    public function when_the_same_idempotency_key_is_replayed_then_the_transfer_is_not_executed_twice(): void
    {
        // Given a verified case executed once under an explicit key
        $company = Company::factory()->create();
        $alex = $this->aCompanyMember($company, 'employee');
        $case = $this->aVerifiedCaseFor($company, $alex);
        $key = (string) Str::uuid7();
        $this->executeCase($this->admin, $case->getKey(), $key)->assertOk();

        // When the identical request is replayed under the SAME key
        $replay = $this->executeCase($this->admin, $case->getKey(), $key);

        // Then the remembered response returns and nothing runs twice
        $this->assertExecutedExactlyOnce($case, $replay);
    }

    #[Test]
    public function when_a_routine_transfer_preceded_the_case_then_the_closed_case_cites_its_own_transfer_event(): void
    {
        // Given a company whose ownership already moved once via the REAL
        // routine path (event 1, caseId NULL) …
        $company = Company::factory()->create();
        $olive = User::query()->findOrFail($company->owner_id);
        $bob = $this->aCompanyMember($company, 'admin');
        app(TransferCompanyOwnershipInteractor::class)->execute(
            $company,
            TransferCompanyOwnershipCommand::validateAndCreate([
                'newOwnerUserId' => $bob->getKey(),
                'transferredBy' => $olive->getKey(),
                'source' => TransferCompanyOwnershipCommand::SOURCE_SELF_SERVICE,
            ]),
            $olive->getKey(),
        );

        // … and a case opened AFTERWARDS, verified for a third member
        $carol = $this->aCompanyMember($company->refresh(), 'employee');
        $case = $this->aVerifiedCaseFor($company, $carol);

        // When the admin executes the case (event 2, caseId set)
        $this->executeCase($this->admin, $case->getKey())->assertOk();

        // Then the closed case cites ITS OWN event by identity, never the older routine one
        $this->assertClosedCaseCitesItsOwnTransferEvent($case);
    }

    /**
     * Call the REAL admin execute endpoint (UC-1-093) as $actor, or as a guest
     * when null. Every call carries an Idempotency-Key, minted fresh unless
     * the row passes its own (§13.2 REQUIRED mount); deliberately NO
     * X-Company-Context — the /api/v1/admin/* family is cross-tenant by
     * design (cd-foundation-http §3).
     */
    protected function executeCase(?User $actor, string $caseId, ?string $idempotencyKey = null): TestResponse
    {
        if ($actor !== null) {
            Sanctum::actingAs($actor);
        }

        return $this->patchJson(
            "/api/v1/admin/ownership-transfer-cases/{$caseId}/execute",
            [],
            ['Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid7()],
        );
    }

    /**
     * The §13.2 REQUIRED mount refused the keyless mutation — 422 naming the
     * missing key in the §S21 envelope (the EnsureIdempotencyKeyTest contract;
     * this row pins only that THIS route mounts it).
     */
    protected function assertRefusedAsMissingIdempotencyKey(TestResponse $response): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    /**
     * The execute endpoint called WITHOUT any Idempotency-Key header — the
     * row-4 omission form of executeCase.
     */
    protected function executeCaseWithoutIdempotencyKey(?User $actor, string $caseId): TestResponse
    {
        if ($actor !== null) {
            Sanctum::actingAs($actor);
        }

        return $this->patchJson("/api/v1/admin/ownership-transfer-cases/{$caseId}/execute");
    }

    /**
     * The closed case's transfer_event_id resolves by IDENTITY: it equals the
     * stored event whose payload names THIS case, differs from the older
     * routine event (caseId NULL), and that cited event carries source=admin
     * — an insert-order read-back could cite the wrong transfer.
     */
    protected function assertClosedCaseCitesItsOwnTransferEvent(OwnershipTransferCase $case): void
    {
        $ownEvent = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $case->company_id)
            ->where('event_class', CompanyOwnershipTransferred::class)
            ->where('event_properties->caseId', $case->getKey())
            ->sole();
        $routineEvent = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $case->company_id)
            ->where('event_class', CompanyOwnershipTransferred::class)
            ->whereNull('event_properties->caseId')
            ->sole();

        $this->assertSame((int) $ownEvent->id, $case->fresh()->transfer_event_id);
        $this->assertNotSame((int) $routineEvent->id, $case->fresh()->transfer_event_id);
        $this->assertSame('admin', $ownEvent->event_properties['source']);
    }

    /**
     * The duplicate submission did NOT re-run the execution — the second
     * response is the remembered one (200 + the replay marker header), the
     * company's stream holds exactly ONE transfer event beside exactly ONE
     * audit row, and the case is still closed executed.
     */
    protected function assertExecutedExactlyOnce(OwnershipTransferCase $case, TestResponse $replay): void
    {
        $replay->assertOk();
        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));

        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $case->company_id)
            ->where('event_class', CompanyOwnershipTransferred::class)
            ->count());
        $this->assertDatabaseCount('audit_trail', 1);

        $fresh = $case->fresh();
        $this->assertSame('closed', $fresh->status->value);
        $this->assertSame('executed', $fresh->closed_outcome->value);
    }

    /**
     * Nothing was executed — the case row is exactly as arranged, the
     * company's stream carries no CompanyOwnershipTransferred event, no audit
     * row exists, and the company's owner column still names $ownerUserId
     * (the refusal invariant: every refusal leaves the world untouched).
     */
    protected function assertNothingExecuted(OwnershipTransferCase $case, string $ownerUserId): void
    {
        $this->assertCaseUnchanged($case);

        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $case->company_id)
            ->where('event_class', CompanyOwnershipTransferred::class)
            ->count());

        $this->assertNoAuditRecorded();

        $this->assertSame($ownerUserId, Company::query()->whereKey($case->company_id)->sole()->owner_id);
    }
}
