<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use RuntimeException;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Companies\Enums\OwnershipTransferCaseOutcome;
use App\Companies\Events\CompanyOwnershipTransferred;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\Notifications\Mail\SystemNotificationMail;
use App\Companies\UseCases\Commands\TransferCompanyOwnershipCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\UseCases\Interactors\TransferCompanyOwnershipInteractor;

class ExecuteOwnershipTransferCaseTest extends OwnershipTransferCasesTestCase
{
    use InteractsWithQueuedReactors;

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
        $company->update(['owner_id' => $owner->getKey()]);
        $case = $this->aVerifiedCaseFor($company);

        // When she calls the execute endpoint for that case
        $response = $this->executeCase($owner, $case->getKey());

        // Then the admin surface is never acknowledged and nothing moved
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingExecuted($case, $owner->getKey());
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
    public function when_the_case_id_is_not_a_uuid_then_execution_is_refused_as_invalid(): void
    {
        // Given an allowlisted admin and a case reference that is not a UUID
        // When they execute it
        $response = $this->executeCase($this->admin, 'not-a-uuid');

        // Then the reference is refused as invalid and nothing is audited
        $this->assertValidationRejected($response, 'caseId', 'The case id field must be a valid UUID.');
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
        $response->assertConflict();
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
        $response->assertConflict();
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
        $response->assertUnprocessable();
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
        $response->assertConflict();
        $response->assertJsonPath('error_code', 'STATE_GUARD_VIOLATION');
        $this->assertNothingExecuted($case, $bob->getKey());
    }

    #[Test]
    public function when_ownership_moved_and_the_member_is_gone_then_the_stale_refusal_wins(): void
    {
        // Given a verified case whose recorded owner was replaced AND whose
        // incoming member has left — both world-moved guards are violated
        $company = Company::factory()->create();
        $alex = $this->aCompanyMember($company, 'employee');
        $case = $this->aVerifiedCaseFor($company, $alex);
        $bob = $this->aCompanyMember($company, 'admin');
        $company->update(['owner_id' => $bob->getKey()]);
        $company->users()->updateExistingPivot($alex->getKey(), ['deleted_at' => now()]);

        // When the admin executes the case
        $response = $this->executeCase($this->admin, $case->getKey());

        // Then the STALE refusal wins — 409 answers the world moving before
        // any cross-entity complaint (the owner seat is the deeper premise)
        $response->assertConflict();
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
        $this->assertFailedClosed($response);
        $this->assertNothingExecuted($case, $company->owner_id);
    }

    /**
     * @param  array{sqlstate: string, code: int, message: string}  $failure
     */
    #[Test]
    #[DataProvider('contentionProvider')]
    public function when_the_execution_hits_lock_contention_then_it_is_refused_as_a_conflict(
        array $failure,
        int $expectedStatus,
        ApiErrorCode $expected,
    ): void {
        // Given a verified case, a live incoming member, and a write inside
        // the OUTER locked transaction — after the composed transfer — that
        // dies the way the driver reports it
        $company = Company::factory()->create();
        $alex = $this->aCompanyMember($company, 'employee');
        $case = $this->aVerifiedCaseFor($company, $alex);
        AuditEntry::created(fn () => throw $this->syntheticQueryException($failure));

        // When the admin executes the case
        $response = $this->executeCase($this->admin, $case->getKey());

        // Then contention answers as a conflict; anything else passes through
        // (render only — the audit-veto row is the atomicity net)
        $this->assertRefusedWith($response, $expectedStatus, $expected);
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
        $response->assertUnprocessable();
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
        $fresh = $case->fresh();
        $this->assertInstanceOf(OwnershipTransferCase::class, $fresh);
        $this->assertSame('closed', $fresh->status->value);
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

    #[Test]
    public function when_the_admin_executes_a_verified_case_then_the_ownership_moves_whole(): void
    {
        // Given a frozen clock, Contoso recording Olive as its owner, and a
        // verified case naming live member Alex (emergency contact on file)
        $this->freezeTime();
        Mail::fake();
        $company = Company::factory()->create();
        $olive = User::query()->findOrFail($company->owner_id);
        $company->users()->attach($olive->getKey(), ['role_id' => $this->seededRole('owner')->getKey()]);
        $alex = $this->aCompanyMember($company, 'employee');
        $company->users()->updateExistingPivot($alex->getKey(), [
            'emergency_contact_name' => 'Sam Perera',
            'emergency_contact_email' => 'sam@example.com',
        ]);
        $case = $this->aVerifiedCaseFor($company, $alex);
        Queue::fake();

        // When the admin executes the case
        $response = $this->executeCase($this->admin, $case->getKey());

        // Then the swap, the recorded fact, the closed case, the audit row
        // and the wire contract land whole — and only Alex is told
        $eventId = $this->assertAdminTransferRecorded($case, $olive, $alex);
        $this->assertOwnershipMovedByCase($case, $olive, $alex);
        $this->assertCaseClosedExecuted($case, $eventId);
        $this->assertExecutionAudited($case, $eventId, $response);
        $this->assertExecutedCaseOnTheWire($response, $case);
        $this->runQueuedHandlersFor(CompanyOwnershipTransferred::class);
        $this->assertOnlyNewOwnerNotifiedOfCase($company, $alex, $olive);
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
        $response->assertUnprocessable();
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
     * Exactly ONE CompanyOwnershipTransferred names this case in its payload
     * (sole — by identity), pinned end-to-end: previous/new owner, the acting
     * admin as transferredBy, and source=admin. Returns the stored-event id
     * the close and the audit row must both cite.
     */
    protected function assertAdminTransferRecorded(OwnershipTransferCase $case, User $from, User $to): int
    {
        $event = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $case->company_id)
            ->where('meta_data->company_id', $case->company_id)
            ->where('event_class', CompanyOwnershipTransferred::class)
            ->where('event_properties->caseId', $case->getKey())
            ->sole();

        $this->assertSame($from->getKey(), $event->event_properties['previousOwnerUserId']);
        $this->assertSame($to->getKey(), $event->event_properties['newOwnerUserId']);
        $this->assertSame($this->admin->getKey(), $event->event_properties['transferredBy']);
        $this->assertSame('admin', $event->event_properties['source']);

        return (int) $event->id;
    }

    /**
     * The dual representation flipped together (the UC-1-028-identical swap):
     * companies.owner_id names $to, $to's pivot role is owner, and $from is
     * left an active admin — never roleless, never removed.
     */
    protected function assertOwnershipMovedByCase(OwnershipTransferCase $case, User $from, User $to): void
    {
        $this->assertSame($to->getKey(), Company::query()->whereKey($case->company_id)->sole()->owner_id);

        $this->assertSame($this->seededRole('owner')->getKey(), $this->pivotOf($case, $to)->role_id);

        $fromPivot = $this->pivotOf($case, $from);
        $this->assertSame($this->seededRole('admin')->getKey(), $fromPivot->role_id);
        $this->assertNull($fromPivot->deleted_at);
    }

    /**
     * The case closed executed under the frozen clock: outcome + both stamps
     * instant-pinned to now(), transfer_event_id cites exactly the event this
     * execution recorded, and the verify columns are untouched.
     */
    protected function assertCaseClosedExecuted(OwnershipTransferCase $case, int $transferEventId): void
    {
        $fresh = $case->fresh();
        $this->assertInstanceOf(OwnershipTransferCase::class, $fresh);

        $this->assertSame('closed', $fresh->status->value);
        $this->assertSame('executed', $fresh->closed_outcome?->value);
        $this->assertSame(now()->toDateTimeString(), $fresh->executed_at?->toDateTimeString());
        $this->assertSame($this->admin->getKey(), $fresh->executed_by);
        $this->assertSame($transferEventId, $fresh->transfer_event_id);
        $this->assertSame($case->verified_at?->toDateTimeString(), $fresh->verified_at?->toDateTimeString());
    }

    /**
     * Exactly one audit row evidences the execution, in the cd-audit-trail
     * shape: action case_executed on the case subject, stored_event_id citing
     * the transfer event (the module's SECOND non-null producer), actor trio
     * pinned, and the request-context columns IDENTITY-pinned (the T4 lesson —
     * presence-only asserts cannot catch nulls threaded through).
     */
    protected function assertExecutionAudited(OwnershipTransferCase $case, int $transferEventId, TestResponse $response): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'event_class' => 'CompanyOwnershipTransferred',
            'subject_type' => 'ownership_transfer_case',
            'subject_id' => $case->getKey(),
            'subject_label' => null,
            'action' => 'case_executed',
            'description' => 'Platform admin executed a verified ownership-transfer case.',
            'company_id' => $case->company_id,
            'actor_user_id' => $this->admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $this->admin->name,
            'stored_event_id' => $transferEventId,
            'metadata' => null,
            'severity' => 'info',
            'ip_address' => '127.0.0.1',
        ]);

        $entry = AuditEntry::query()->sole();
        $this->assertSame($response->headers->get('Correlation-Id'), $entry->request_id);
        $this->assertNotNull($entry->user_agent);
    }

    /**
     * The executed case on the wire — 200 with the FULL admin contract locked
     * byte-for-byte (11 keys as of T7: + executed_at/executed_by; the
     * transfer_event_id, notes and artefacts stay OFF the wire).
     */
    protected function assertExecutedCaseOnTheWire(TestResponse $response, OwnershipTransferCase $case): void
    {
        $fresh = $case->fresh();
        $this->assertInstanceOf(OwnershipTransferCase::class, $fresh);

        $response->assertOk();
        $response->assertExactJson(['data' => [
            'id' => $fresh->getKey(),
            'company_id' => $fresh->company_id,
            'status' => 'closed',
            'closed_outcome' => 'executed',
            'rejection_reason_code' => null,
            'new_owner_user_id' => $fresh->new_owner_user_id,
            'requested_context' => $fresh->requested_context,
            'verified_at' => $fresh->verified_at?->toISOString(),
            'verified_by' => $fresh->verified_by,
            'executed_at' => $fresh->executed_at?->toISOString(),
            'executed_by' => $fresh->executed_by,
            'created_at' => $fresh->created_at->toISOString(),
        ]]);
    }

    /**
     * Exactly the new owner learned about the transfer (D4 absence-as-behavior):
     * one ownership_received in-app row for $to carrying the company name, the
     * queued mail leg addressed to $to, and NOTHING for $from — on the admin
     * path they are deceased or incapacitated.
     */
    protected function assertOnlyNewOwnerNotifiedOfCase(Company $company, User $to, User $from): void
    {
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $to->getKey(),
            'company_id' => $company->id,
            'data->notification_type' => 'ownership_received',
            'data->company_name' => $company->name,
        ]);
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $from->getKey())->count());
        Mail::assertQueued(SystemNotificationMail::class, fn (SystemNotificationMail $mail): bool => $mail->hasTo($to->email));
    }

    /**
     * The membership pivot row joining this case's company to $user.
     */
    protected function pivotOf(OwnershipTransferCase $case, User $user): CompanyUser
    {
        return CompanyUser::query()
            ->where('company_id', $case->company_id)
            ->where('user_id', $user->getKey())
            ->withTrashed()
            ->sole();
    }

    /**
     * The closed case's transfer_event_id resolves by IDENTITY: it equals the
     * stored event whose payload names THIS case, differs from the older
     * routine event (caseId NULL), and that cited event carries source=admin.
     * This arrange kills min/first-order read-backs and a filterless sole();
     * a max-order read-back is coincidentally equivalent under the company
     * row lock — the caseId filter, not ordering, is the guard being pinned.
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

        $fresh = $case->fresh();
        $this->assertInstanceOf(OwnershipTransferCase::class, $fresh);
        $this->assertSame((int) $ownEvent->id, $fresh->transfer_event_id);
        $this->assertNotSame((int) $routineEvent->id, $fresh->transfer_event_id);
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
        $this->assertInstanceOf(OwnershipTransferCase::class, $fresh);
        $this->assertSame('closed', $fresh->status->value);
        $this->assertSame('executed', $fresh->closed_outcome?->value);
    }

    /**
     * Nothing was executed — the case row is exactly as arranged, the
     * company's stream carries no CompanyOwnershipTransferred event, no audit
     * row exists, the company's owner column still names $ownerUserId, and
     * nobody else was promoted to an owner-role pivot (the split-brain arm:
     * a half-applied swap must fail here even if everything else rolls back).
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

        $this->assertSame(0, CompanyUser::query()
            ->where('company_id', $case->company_id)
            ->where('role_id', $this->seededRole('owner')->getKey())
            ->where('user_id', '!=', $ownerUserId)
            ->withTrashed()
            ->count());
    }
}
