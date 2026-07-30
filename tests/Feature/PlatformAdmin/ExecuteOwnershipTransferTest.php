<?php

namespace Tests\Feature\PlatformAdmin;

use stdClass;
use App\Models\User;
use RuntimeException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use Illuminate\Support\Facades\Notification;
use App\Companies\Projectors\CompanyProjector;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\EventSourcing\Facades\Projectionist;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\UseCases\Values\OwnershipSwap;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExecuted;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use Spatie\EventSourcing\AggregateRoots\Exceptions\CouldNotPersistAggregate;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferIdentityVerified;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipReceivedNotification;
use App\Companies\OwnershipTransfers\Projectors\OwnershipTransferPivotHistoryProjector;
use App\Companies\OwnershipTransfers\UseCases\Commands\ExecuteOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\IO\Notifications\EmergencyContactRequiredNotification;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferredAwayNotification;
use App\Companies\OwnershipTransfers\Aggregates\OwnershipTransferRequest as OwnershipTransferAggregate;

/**
 * SM-1-05 T4 — a platform admin executes a verified transfer, moving ownership
 * atomically (UC-1-093, slice #11 Task 8). One behavior, one seam, one actor, so
 * the illegal-transition refusals, the live re-validation guards and the happy
 * swap all live here, ordered Sad → Edge → Happy (§2.14 Rule A).
 *
 * The heaviest increment of the slice: five write surfaces commit together or
 * none do (SD-1-02 §2.2), across four $weight-ordered projectors.
 *
 * Two deliberate asymmetries with the siblings, both s159 rulings:
 * - Unlike reject (H4) and withdraw, a legal hold DOES refuse here (P5): §S10b
 *   freezes ownership CHANGES, and this is the one transition that changes one.
 * - Unlike every other transition, execute re-validates live state before the
 *   aggregate call (P3) — the owner and the target could both have moved since
 *   T1, and the aggregate never reads the database.
 */
class ExecuteOwnershipTransferTest extends OwnershipTransferAdminTestCase
{
    /**
     * The refusal left every T4 write surface untouched. Five aspects, because
     * a 409 on its own says only that the HTTP layer answered — it says nothing
     * about whether the aggregate recorded, the owner moved, or the target was
     * promoted on the way to answering.
     *
     * Derives rather than snapshots: the pre-transfer owner IS
     * `current_owner_user_id` on the transfer row, so no BEFORE capture is
     * needed and the helper stays at two parameters (§2.2 Rule 3).
     *
     * Recorded plainly: none of these aspects means anything until something can
     * violate them, and `assertDatabaseCount(..., 0)` against a table nothing
     * writes is the emptiest kind of green. The happy-path row is the positive
     * control for all five — it drives the same five surfaces the other way, and
     * fails if any of them stays put.
     */
    private function assertNoWriteSurvived(
        OwnershipTransferRequest $transfer,
        OwnershipTransferStatus $expected,
    ): void {
        $current = $this->currentTransfer($transfer);

        $this->assertSame($expected, $current->status);
        // Scoped to THIS aggregate, not the whole store. Unscoped, the helper
        // could only ever be used in a test where nothing else executed — which
        // is precisely the tenant-isolation row it most needs to serve.
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferExecuted::class)
            ->where('aggregate_uuid', $transfer->getKey())
            ->count());
        // Stated as "the T4 write did not happen" rather than "the owner is
        // still the recorded one". The two agree everywhere except the row whose
        // premise is that a PRIOR transfer already moved the owner — there the
        // stronger form is false by construction, and asserting it would have
        // made the helper unusable for exactly the case it most needs to cover.
        $this->assertNotSame(
            $transfer->target_user_id,
            Company::query()->whereKey($transfer->company_id)->value('owner_id'),
        );
        // BOTH pivots unchanged — the design-table aspect, both halves. The
        // target never gained `owner`, and the outgoing owner never lost it:
        // MembershipProjector writes demotion THEN promotion, so observing only
        // the promotion's absence would keep a demotion that escaped the
        // transaction green (s162, fifth-pass finding).
        $this->assertDatabaseMissing('company_user', [
            'company_id' => $transfer->company_id,
            'user_id' => $transfer->target_user_id,
            'role_id' => $this->seededRole('owner')->getKey(),
        ]);
        $this->assertDatabaseHas('company_user', [
            'company_id' => $transfer->company_id,
            'user_id' => $transfer->current_owner_user_id,
            'role_id' => $this->seededRole('owner')->getKey(),
        ]);
        $this->assertSame(0, DB::table('ownership_transfer_pivot_history')
            ->where('request_id', $transfer->getKey())
            ->count());
    }

    /**
     * A valid execution body, sent by every row that isolates something OTHER
     * than validation — so an existence or gate assertion can never be satisfied
     * by a validation failure standing in for it (the RejectOwnershipTransferTest
     * idiom, which states the reasoning at its own not-found row).
     *
     * @return array<string, string>
     */
    private static function executionBody(): array
    {
        return ['reason' => 'Founder departure confirmed by signed board resolution.'];
    }

    #[Test]
    public function when_the_execution_is_unauthenticated_then_it_is_refused(): void
    {
        // Given a transfer awaiting execution
        [$company] = $this->aCompanyWithMember('owner');
        $transfer = $this->aTransferInState(
            $company,
            User::factory()->create(),
            OwnershipTransferStatus::Requested,
        );

        // When a guest attempts to execute it
        $response = $this->executeTransfer(null, $transfer->getKey());

        // Then the request is refused and the transfer is untouched
        $this->assertUnauthenticated($response);
        $this->assertSame(
            OwnershipTransferStatus::Requested,
            $this->currentTransfer($transfer)->status,
        );
    }

    #[Test]
    #[DataProvider('provideTenantRoles')]
    public function when_the_caller_is_not_a_platform_admin_then_the_transfer_is_not_found(string $roleCode): void
    {
        // Given a verified transfer and a tenant member of its own company
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $this->anAllowlistedAdmin());
        $caller = $this->aCompanyMember($company, $roleCode);

        // When that tenant role attempts to execute it
        $response = $this->executeTransfer($caller, $transfer->getKey(), self::executionBody());

        // Then the admin surface never acknowledges the transfer exists
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertSame(
            OwnershipTransferStatus::Verified,
            $this->currentTransfer($transfer)->status,
        );
    }

    #[Test]
    public function when_the_transfer_does_not_exist_then_it_is_not_found(): void
    {
        // Given an allowlisted admin and an id no transfer carries
        $admin = $this->anAllowlistedAdmin();

        // When they attempt to execute it
        $response = $this->executeTransfer($admin, (string) Str::uuid7(), self::executionBody());

        // Then the refusal is byte-identical to the one a denied caller gets
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertDatabaseCount('ownership_transfer_requests', 0);
    }

    /**
     * Every shape the execution record must refuse. The reason is mandatory AT
     * WRITE TIME (UC-1-093 error flow 4a): T4 is the one transition that moves
     * legal authority, so no execution may reach stored_events unexplained.
     *
     * Whitespace-only needs no rule of its own: TrimStrings and
     * ConvertEmptyStringsToNull both sit ahead of validation in the HTTP
     * pipeline, so `'   '` arrives as null and fails `required` — and Laravel's
     * own `required` trims besides. Both rows stay because they are distinct
     * CLIENT shapes; what they jointly pin is that neither reaches the event
     * store, whichever layer happens to stop them.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidExecutionInputs(): array
    {
        return [
            'reason missing' => [[], 'reason'],
            'reason empty' => [['reason' => ''], 'reason'],
            'reason whitespace only' => [['reason' => '   '], 'reason'],
            'reason over the 1000-char bound' => [['reason' => str_repeat('a', 1001)], 'reason'],
        ];
    }

    #[Test]
    #[DataProvider('provideInvalidExecutionInputs')]
    public function when_the_execution_input_is_invalid_then_it_is_refused(array $body, string $invalidField): void
    {
        // Given a verified transfer awaiting execution
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);

        // When an allowlisted admin submits a malformed execution
        $response = $this->executeTransfer($admin, $transfer->getKey(), $body);

        // Then it is refused and the transfer never leaves the verified window
        $this->assertValidationRejected($response, $invalidField);
        $this->assertSame(
            OwnershipTransferStatus::Verified,
            $this->currentTransfer($transfer)->status,
        );
    }

    #[Test]
    public function when_the_transfer_is_not_yet_verified_then_it_is_not_executed(): void
    {
        // Given a transfer whose identity check has not happened yet
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, $this->aCompanyMember($company));

        // When an allowlisted admin attempts to execute it
        $response = $this->executeTransfer($this->anAllowlistedAdmin(), $transfer->getKey(), self::executionBody());

        // Then the state guard refuses it and nothing was written
        $this->assertStateGuardRefused($response, 'This ownership transfer cannot be executed.');
        $this->assertNoWriteSurvived($transfer, OwnershipTransferStatus::Requested);
    }

    #[Test]
    public function when_the_transfer_is_already_rejected_then_it_is_not_executed(): void
    {
        // Given a transfer a platform admin has already rejected
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aRejectedTransferStream($company, $owner, $admin);

        // When an allowlisted admin attempts to execute it
        $response = $this->executeTransfer($admin, $transfer->getKey(), self::executionBody());

        // Then the state guard refuses it and nothing was written
        $this->assertStateGuardRefused($response, 'This ownership transfer cannot be executed.');
        $this->assertNoWriteSurvived($transfer, OwnershipTransferStatus::Rejected);
    }

    /**
     * The aggregate answers before any cross-entity resolution does.
     *
     * A closed transfer whose target has since left the company is an entirely
     * ordinary sequence — rejected on Monday, the employee leaves on Friday,
     * someone retries execute. Two things are true about it, and only one is
     * worth telling the admin: the transfer is closed, and the target is no
     * longer a member. The second is a consequence of time passing, not a reason
     * the execution failed.
     *
     * Ruling P14 fixed this pre-emption at the root: `guardIsExecutable()`
     * answers before any cross-entity work — the owner guard and the swap
     * resolution alike sit behind it. (This row originally leaned on the s160
     * guard-scoping instead; that scoping read the projection's status where
     * the aggregate reads the stream's, and was removed at s162.)
     *
     * The existing rows cannot see it: the departed-target row uses a VERIFIED
     * transfer, and the rejected row uses a target who is still a member. It
     * takes both conditions at once.
     */
    #[Test]
    public function when_a_closed_transfer_has_a_departed_target_then_it_is_refused_as_unexecutable(): void
    {
        // Given a rejected transfer whose target has since left the company
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aRejectedTransferStream($company, $owner, $admin);
        $this->theTargetLeavesTheCompany($transfer);

        // When an allowlisted admin attempts to execute it
        $response = $this->executeTransfer($admin, $transfer->getKey(), self::executionBody());

        // Then the transfer's own state is the answer, not the stale membership
        $this->assertStateGuardRefused($response, 'This ownership transfer cannot be executed.');
        $this->assertNoWriteSurvived($transfer, OwnershipTransferStatus::Rejected);
    }

    #[Test]
    public function when_the_transfer_is_already_withdrawn_then_it_is_not_executed(): void
    {
        // Given a transfer its own requester has withdrawn
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aCancelledTransferStream($company, $owner);

        // When an allowlisted admin attempts to execute it
        $response = $this->executeTransfer($this->anAllowlistedAdmin(), $transfer->getKey(), self::executionBody());

        // Then the state guard refuses it and nothing was written
        $this->assertStateGuardRefused($response, 'This ownership transfer cannot be executed.');
        $this->assertNoWriteSurvived($transfer, OwnershipTransferStatus::Cancelled);
    }

    #[Test]
    public function when_the_transfer_has_already_expired_then_it_is_not_executed(): void
    {
        // Given a transfer the daily scan has already expired
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->anExpiredTransferStream($company, $owner);

        // When an allowlisted admin attempts to execute it
        $response = $this->executeTransfer($this->anAllowlistedAdmin(), $transfer->getKey(), self::executionBody());

        // Then the state guard refuses it and nothing was written
        $this->assertStateGuardRefused($response, 'This ownership transfer cannot be executed.');
        $this->assertNoWriteSurvived($transfer, OwnershipTransferStatus::Expired);
    }

    /**
     * A prior transfer moved ownership elsewhere while this one sat verified.
     * A named fixture helper carrying the column write is the sanctioned shape —
     * §2.13 bans inline overrides in test BODIES, not in fixtures (the
     * `aTransferDueAt` / `placeCompanyUnderLegalHold` precedents).
     */
    private function theOwnerChangesTo(Company $company, User $newOwner): void
    {
        $company->forceFill(['owner_id' => $newOwner->getKey()])->save();
    }

    #[Test]
    public function when_the_owner_has_changed_since_the_request_then_it_is_not_executed(): void
    {
        // Given a verified transfer whose recorded owner is no longer the live one
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);
        $this->theOwnerChangesTo($company, $this->aCompanyMember($company, 'admin'));

        // When an allowlisted admin executes it
        $response = $this->executeTransfer($admin, $transfer->getKey(), self::executionBody());

        // Then the live re-validation refuses it and nothing was written
        $this->assertStateGuardRefused($response, 'The company owner has changed since this transfer was requested.');
        $this->assertNoWriteSurvived($transfer, OwnershipTransferStatus::Verified);
    }

    /**
     * The projection's status is written by a projector; the aggregate's is
     * replayed from the stream. They agree everywhere except mid-replay, on a
     * partial failure, or after manual intervention — and ruling O1 (s157) is
     * the standing lesson that acting on the projection's answer while the
     * stream says otherwise is how a real corruption shipped.
     *
     * At s160 the owner guard early-returned on the PROJECTION's status while
     * `guardIsExecutable()` had just read the STREAM's — so on exactly this
     * divergence it skipped the owner check when the state was least
     * trustworthy. This row pins the corrected posture: the owner premise is
     * re-validated no matter what the projection claims (s162).
     */
    #[Test]
    public function when_the_projection_lags_behind_the_stream_then_a_changed_owner_is_still_refused(): void
    {
        // Given a verified stream whose projection lags and whose owner moved on
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);
        $this->theProjectionLagsBehindTheStream($transfer);
        $this->theOwnerChangesTo($company, $this->aCompanyMember($company, 'admin'));

        // When an allowlisted admin executes it
        $response = $this->executeTransfer($admin, $transfer->getKey(), self::executionBody());

        // Then the owner re-validation still refuses it and nothing was written
        $this->assertStateGuardRefused($response, 'The company owner has changed since this transfer was requested.');
        $this->assertNoWriteSurvived($transfer, OwnershipTransferStatus::Requested);
    }

    /**
     * A projection row whose status lags its own stream — the O1 divergence
     * class. A named fixture helper carrying the column write is the sanctioned
     * shape (§2.13; the `theOwnerChangesTo` precedent above).
     */
    private function theProjectionLagsBehindTheStream(OwnershipTransferRequest $transfer): void
    {
        $transfer->forceFill(['status' => OwnershipTransferStatus::Requested])->save();
    }

    /**
     * The target was removed from the company between T1 and T4 — their pivot is
     * soft-deleted, which is how this codebase ends a membership (ERD-1-01 D5b:
     * rows survive so a re-invite can undelete them).
     */
    private function theTargetLeavesTheCompany(OwnershipTransferRequest $transfer): void
    {
        CompanyUser::query()
            ->where('company_id', $transfer->company_id)
            ->where('user_id', $transfer->target_user_id)
            ->delete();
    }

    /**
     * The 422 mirrors T1's own refusal of a non-member target (ruling F4,
     * ValidationException → VALIDATION_FAILED), so the two ends of the transfer
     * answer the same question the same way.
     *
     * Worth naming: the error is keyed to `targetUserId`, which at T4 is NOT a
     * request field — execute accepts no target (P3). The key names the fact the
     * TRANSFER recorded, not something the caller can correct by resending. That
     * is a deliberate consistency with T1 rather than an oversight, and it is
     * why the message speaks about the selected user rather than the input.
     */
    #[Test]
    public function when_the_target_is_no_longer_a_member_then_it_is_not_executed(): void
    {
        // Given a verified transfer whose target has since left the company
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);
        $this->theTargetLeavesTheCompany($transfer);

        // When an allowlisted admin executes it
        $response = $this->executeTransfer($admin, $transfer->getKey(), self::executionBody());

        // Then the live membership re-check refuses it and nothing was written.
        // The message is pinned verbatim (ruling P21, s162): it must match T1's
        // twin refusal exactly — the two ends of the transfer answering the
        // same question the same way IS the contract (ruling F4).
        $this->assertValidationRejected($response, 'targetUserId');
        $response->assertJsonPath(
            'errors.targetUserId.0',
            'The selected target user is not a member of this company.',
        );
        $this->assertNoWriteSurvived($transfer, OwnershipTransferStatus::Verified);
    }

    /**
     * Ruling P5, and the transition that settles the B1-vs-H4 asymmetry.
     *
     * Verify refuses under a hold (B1); reject and withdraw proceed (H4), on the
     * stated test *"does it change ownership?"*. T4 is the one transition whose
     * answer is yes, so the family's own reasoning decides it rather than a new
     * principle.
     *
     * The guard is an INTERACTOR guard, following B1: `legal_hold` belongs to the
     * Company aggregate and the OT aggregate never reads the database, so this
     * cannot live on the aggregate at all.
     *
     * Its scoping companion — `when_another_company_is_under_legal_hold_then
     * _this_transfer_still_executes` — sits with the edge rows rather than here,
     * because it executes successfully. The pair is deliberately split by
     * category (§2.2 Rule 1) rather than kept adjacent.
     */
    #[Test]
    public function when_the_company_is_under_legal_hold_then_it_is_not_executed(): void
    {
        // Given a verified transfer whose company has since been frozen
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);
        $this->placeCompanyUnderLegalHold($company);

        // When an allowlisted admin executes it
        $response = $this->executeTransfer($admin, $transfer->getKey(), self::executionBody());

        // Then the hold refuses it and nothing was written
        $this->assertStateGuardRefused(
            $response,
            'This company is under a legal hold; ownership changes are frozen until it is lifted.',
        );
        $this->assertNoWriteSurvived($transfer, OwnershipTransferStatus::Verified);
    }

    #[Test]
    public function when_the_transfer_is_already_executed_then_it_is_not_executed_again(): void
    {
        // Given a transfer whose ownership swap has already happened
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->anExecutedTransferStream($company, $owner, $admin);
        $target = User::query()->findOrFail($transfer->target_user_id);

        // When an allowlisted admin attempts to execute it a second time
        $response = $this->executeTransfer($admin, $transfer->getKey(), self::executionBody());

        // Then the state guard refuses it and the FIRST execution stands intact
        $this->assertStateGuardRefused($response, 'This ownership transfer cannot be executed.');
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferExecuted::class)
            ->count());
        $this->assertOwnershipMoved($transfer, $owner, $target);
        $this->assertPivotHistoryRecorded($transfer, $owner, $target);
    }

    /**
     * The one admin transition that mounts `idempotency-key:required`
     * (api-conventions §13.2 names ownership-transfer-execute explicitly, and
     * verify/reject deliberately do NOT — rulings G1 and s151 STALE-3).
     *
     * The asymmetry is earned: those two are self-deduplicating, because a
     * duplicate click meets a state guard that has already moved. Execute is
     * too, in the same way — but it is the one transition where losing that race
     * silently would move legal authority, so the contract does not rely on the
     * guard alone.
     */
    #[Test]
    public function when_the_execution_omits_the_idempotency_key_then_it_is_refused(): void
    {
        // Given a verified transfer awaiting execution
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);

        // When an allowlisted admin executes it with no Idempotency-Key
        $response = $this->executeTransferWithoutKey($admin, $transfer->getKey(), self::executionBody());

        // Then the mount refuses it before any write happens
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->assertNoWriteSurvived($transfer, OwnershipTransferStatus::Verified);
    }

    /**
     * The replayed click the `idempotency-key:required` mount exists to absorb —
     * the route's own stated justification, which nothing exercised.
     *
     * The missing-key row above proves the mount is PRESENT. It says nothing
     * about what the mount then does, and those are different claims: a
     * duplicate submission must replay the first answer, not meet the state
     * guard with a 409 for a transfer the caller successfully executed a moment
     * ago. The distinction matters most precisely here, because this is the one
     * admin transition where losing that race silently would move legal
     * authority (api-conventions §13.2 names ownership-transfer-execute).
     *
     * Ownership moves exactly once — one stored event, whichever answer the
     * second call returns.
     */
    #[Test]
    public function when_the_execution_is_replayed_under_the_same_key_then_it_is_not_executed_twice(): void
    {
        // Given a verified transfer
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);

        // When an allowlisted admin submits the same execution twice under one key
        [$first, $replay] = $this->executeTransferTwiceWithSameKey(
            $admin,
            $transfer->getKey(),
            self::executionBody(),
        );

        // Then the replay is served the first answer, and ownership moved once
        $first->assertOk();
        $replay->assertOk();
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferExecuted::class)
            ->count());
    }

    /**
     * E-f, half one (ruling P11). The 409 has NO natural HTTP path: within one
     * request retrieve/execute/persist are adjacent, so a second sequential call
     * meets the state guard long before the version check — row 9 is that. The
     * race needs two genuinely concurrent requests, which a sequential suite
     * cannot stage.
     *
     * So this characterizes the invariant directly, via SD-1-02 §7's own recipe:
     * two retrieves at the same version, both persisting. It is NOT a test of
     * Spatie. It is a test that the version check is live in this configuration —
     * without it the render arm could faithfully map an exception our stack never
     * produces, with every gate green: the silently-dead-nightly-mutation class.
     *
     * SCOPE CORRECTED (s161). This row previously also claimed to prove
     * *"`stored_events` really carries its (uuid, version) UNIQUE"*. It does not,
     * and cannot: `persistWithoutApplyingToEventHandlers()` runs
     * `ensureNoOtherEventsHaveBeenPersisted()` — an in-memory-vs-DB compare —
     * BEFORE it attempts any insert, so the loser here throws without the index
     * ever being consulted. Comment the UNIQUE out of the migration and this row
     * stays green.
     *
     * The distinction is not academic. That PHP compare is itself check-then-act:
     * two genuinely concurrent requests both read the same latest version, both
     * pass it, and both attempt the insert. The UNIQUE is what stops the second,
     * and `when_two_events_claim_one_aggregate_version_then_the_store_refuses_the_second`
     * is the row that holds it.
     *
     * BORN GREEN by construction and it says so plainly — the invariant pre-dates
     * this task, so there is no RED to watch. Its worth is regression: it fails
     * the day the constraint or the check is lost. The render half is proven
     * separately by the `error-concurrent-modification` probe (ApiErrorResponseTest).
     */
    #[Test]
    public function when_a_stale_aggregate_instance_is_persisted_then_the_event_store_refuses_it(): void
    {
        // Given a verified transfer and TWO instances retrieved at the same version
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);
        $swap = $this->swapFor($transfer);
        $command = ExecuteOwnershipTransferCommand::validateAndCreate([
            'reason' => 'Founder departure confirmed by signed board resolution.',
            'executedBy' => $admin->getKey(),
        ]);

        $winner = OwnershipTransferAggregate::retrieve($transfer->getKey());
        $loser = OwnershipTransferAggregate::retrieve($transfer->getKey());

        // When the first persists and the second then tries to record against the
        // version it captured
        $winner->execute($command, $swap)->persist();

        try {
            $loser->execute($command, $swap)->persist();
            $this->fail('Expected CouldNotPersistAggregate for the stale instance.');
        } catch (CouldNotPersistAggregate) {
            // Then exactly one execution reached the store — the winner's
            $this->assertSame(1, LesilyStoredEvent::query()
                ->where('event_class', OwnershipTransferExecuted::class)
                ->count());
            $this->assertSame(OwnershipTransferStatus::Executed, $this->currentTransfer($transfer)->status);
        }
    }

    /**
     * E-f, the half the row above does NOT cover — and the half that actually
     * protects production.
     *
     * `persistWithoutApplyingToEventHandlers()` calls
     * `ensureNoOtherEventsHaveBeenPersisted()` FIRST, an in-memory-vs-DB version
     * compare, and only then attempts the insert inside a `catch (QueryException)`
     * that converts a duplicate key into the same `CouldNotPersistAggregate`. Two
     * sequential `retrieve()` calls therefore never reach the INSERT, so the row
     * above exercises the PHP compare and never touches the UNIQUE — its own
     * docblock claimed otherwise. Drop the index from the migration and it stays
     * green.
     *
     * That matters because the PHP compare is itself check-then-act: under two
     * genuinely concurrent requests both instances read the same latest version,
     * both pass the compare, and both attempt to insert it. **The UNIQUE is the
     * only thing that stops the second**, and it was the one thing untested.
     *
     * Driven at the store directly, because that is the only way to reach past a
     * guard that answers first. Rule 9 shape: the throw is not the last
     * observable — that the stream did not grow is.
     */
    #[Test]
    public function when_two_events_claim_one_aggregate_version_then_the_store_refuses_the_second(): void
    {
        // Given an executed transfer, and the highest event its stream carries
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->anExecutedTransferStream($company, $owner, $admin);

        $latest = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $transfer->getKey())
            ->orderByDesc('aggregate_version')
            ->firstOrFail();
        $streamLength = LesilyStoredEvent::query()->where('aggregate_uuid', $transfer->getKey())->count();

        // When a second event claims a version the stream already holds
        try {
            DB::table('stored_events')->insert([
                'aggregate_uuid' => $latest->aggregate_uuid,
                'aggregate_version' => $latest->aggregate_version,
                'event_class' => $latest->event_class,
                'event_properties' => '{}',
                'meta_data' => '{}',
                'created_at' => CarbonImmutable::now(),
            ]);

            $this->fail('Expected the event store to refuse a duplicate (aggregate_uuid, aggregate_version).');
        } catch (UniqueConstraintViolationException) {
            // Then the store refused it and the stream is unchanged
            $this->assertSame(
                $streamLength,
                LesilyStoredEvent::query()->where('aggregate_uuid', $transfer->getKey())->count(),
            );
        }
    }

    /**
     * The swap as the interactor would resolve it. Built here rather than reached
     * for through the interactor deliberately: this row is about the STORE, so it
     * must not fail for any reason to do with resolution.
     */
    private function swapFor(OwnershipTransferRequest $transfer): OwnershipSwap
    {
        $pivotOf = fn (string $userId): CompanyUser => CompanyUser::query()
            ->where('company_id', $transfer->company_id)
            ->where('user_id', $userId)
            ->sole();

        $outgoing = $pivotOf($transfer->current_owner_user_id);
        $incoming = $pivotOf($transfer->target_user_id);

        return new OwnershipSwap(
            companyId: $transfer->company_id,
            oldOwnerUserId: $transfer->current_owner_user_id,
            newOwnerUserId: $transfer->target_user_id,
            oldOwnerMembershipId: $outgoing->getKey(),
            oldOwnerOldRoleId: $outgoing->role_id,
            oldOwnerNewRoleId: $this->seededRole('admin')->getKey(),
            newOwnerMembershipId: $incoming->getKey(),
            newOwnerOldRoleId: $incoming->role_id,
            newOwnerNewRoleId: $this->seededRole('owner')->getKey(),
        );
    }

    /**
     * A second transfer at a different tenant is the only thing that makes the
     * projectors' `whereKey`/`company_id` scoping observable at all — with one
     * row in the fixture an unscoped write hits the right row by accident (s149
     * C6). Executing one transfer must leave the other's five surfaces alone.
     */
    #[Test]
    public function when_a_transfer_is_executed_then_no_other_tenants_transfer_is_touched(): void
    {
        // Given a verified transfer, and an open transfer at another tenant
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);
        $bystander = $this->aBystanderTransferAtAnotherCompany();

        // When the first is executed
        $this->executeTransfer($admin, $transfer->getKey(), self::executionBody())->assertOk();

        // Then the bystander is untouched on every surface
        $this->assertNoWriteSurvived($bystander, OwnershipTransferStatus::Requested);
    }

    /**
     * UC-1-093's "Other company ownership unaffected" scenario. Distinct from
     * the bystander row above: there the second company belongs to somebody
     * else, so scoping by `company_id` alone would pass it. Here the SAME user
     * owns both, which is the case a `where('user_id', ...)` scope missing its
     * company qualifier would break — Olive owns Contoso and ACME, and
     * transferring Contoso must not touch ACME.
     */
    #[Test]
    public function when_the_outgoing_owner_owns_another_company_then_that_ownership_is_untouched(): void
    {
        // Given an owner of two companies, transferring only the first
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);
        $otherCompany = $this->aSecondCompanyOwnedBy($owner);

        // When the first company's transfer is executed
        $this->executeTransfer($admin, $transfer->getKey(), self::executionBody())->assertOk();

        // Then the second company still belongs to them, at the owner role
        $this->assertSame(
            $owner->getKey(),
            Company::query()->whereKey($otherCompany->getKey())->value('owner_id'),
        );
        $this->assertSame('owner', $this->pivotRoleCodeOf($otherCompany->getKey(), $owner));
    }

    /**
     * SD-1-02 §2.2, and the single most load-bearing row in this class: five
     * write surfaces commit together or none do.
     *
     * The seam is a container-bound throwing subclass of `CompanyProjector`
     * (ruling P8). It works because `Projectionist::callEventHandler` re-resolves
     * every handler at dispatch — `app($eventHandler::class)` — a line Spatie's
     * own comment says exists so tests can substitute fakes. The subclass
     * inherits `$weight = 30`, so it throws AFTER the transfer row (w10) and both
     * pivots (w20) have already been written inside the transaction. That is what
     * makes this a rollback test rather than a never-started one: there is real
     * work to undo.
     *
     * Task R1's F-b reuses this seam for its per-row isolation test.
     */
    #[Test]
    public function when_a_projector_fails_mid_pipeline_then_no_write_survives(): void
    {
        // Given a verified transfer, and a cross-domain projector that will throw
        // once the earlier two have already written
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);

        $this->app->bind(CompanyProjector::class, fn (): CompanyProjector => new class extends CompanyProjector
        {
            public function onOwnershipTransferExecuted(OwnershipTransferExecuted $event): void
            {
                throw new RuntimeException('induced mid-pipeline projector failure');
            }
        });

        // When the execution runs
        $response = $this->executeTransfer($admin, $transfer->getKey(), self::executionBody());

        // Then it fails, and NOTHING the earlier projectors wrote survived —
        // including the stored event itself, which is inside the transaction
        $response->assertStatus(500);
        $this->assertNoWriteSurvived($transfer, OwnershipTransferStatus::Verified);
    }

    /**
     * The order in which $tables were first WRITTEN, read off the query log.
     *
     * Write-only on purpose: the interactor SELECTs `companies` and
     * `company_user` during its guards and swap resolution, well before any
     * projector runs, so a first-TOUCH scan would report the guards' reads as
     * dispatch order and pass while the real order was anything at all.
     *
     * @param  array<int, array{query: string}>  $log
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function firstWriteOrderOf(array $log, array $tables): array
    {
        $order = [];

        foreach ($log as $entry) {
            $sql = strtolower(trim($entry['query']));

            if (! str_starts_with($sql, 'update') && ! str_starts_with($sql, 'insert')) {
                continue;
            }

            foreach ($tables as $table) {
                if (! in_array($table, $order, true) && str_contains($sql, "`{$table}`")) {
                    $order[] = $table;
                }
            }
        }

        return $order;
    }

    /**
     * The tables read under `FOR UPDATE`, in the order first locked.
     *
     * @param  array<int, array{query: string}>  $log
     * @return list<string>
     */
    private function lockedReadTablesIn(array $log): array
    {
        $locked = [];

        foreach ($log as $entry) {
            $sql = strtolower(trim($entry['query']));

            if (! str_starts_with($sql, 'select') || ! str_contains($sql, 'for update')) {
                continue;
            }

            foreach (['companies', 'company_user'] as $table) {
                if (! in_array($table, $locked, true) && str_contains($sql, "`{$table}`")) {
                    $locked[] = $table;
                }
            }
        }

        return $locked;
    }

    /**
     * Every premise the guards establish is one the projectors then act on, so
     * each must be read under a lock held until they have.
     *
     * Without it the window is real and one arm of it corrupts silently: a
     * membership removed between the read and the write leaves `companies
     * .owner_id` pointing at a soft-deleted pivot — `MembershipProjector` uses
     * `withTrashed()`, so it promotes the deleted row without complaint — and the
     * company ends with an owner who can do nothing, recoverable only by support.
     * A concurrent role change is worse for being quiet: the pivot-history row
     * then records an `old_role_id` the member no longer held, falsifying the one
     * column that table exists to carry.
     *
     * Observed as SQL rather than staged as a race, for the reason ruling P9
     * gives: a sequential suite cannot interleave two requests, and a test double
     * would measure the double. The query log substitutes nothing — this is the
     * statement the database actually received.
     *
     * Today it holds only until COMMIT, which is what makes it sufficient: the
     * projectors write inside the same transaction.
     */
    #[Test]
    public function when_the_execution_reads_the_rows_it_will_write_then_it_locks_them(): void
    {
        // Given a verified transfer
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);

        // When it is executed, with every query recorded
        DB::enableQueryLog();
        $this->executeTransfer($admin, $transfer->getKey(), self::executionBody())->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Then locked reads covered both tables the guards must hold. Per-TABLE
        // proof: the helper dedupes, so this pins lock presence on each table,
        // not one lock per pivot row.
        $this->assertSame(['companies', 'company_user'], $this->lockedReadTablesIn($log));
    }

    /**
     * The OQ-1-010 ordering regression, rebuilt on ruling P4's mechanism and
     * observed per ruling P9.
     *
     * It asserts the order the projectors ACTUALLY dispatched in, by watching
     * what they wrote. OQ-1-010's own mandated test enumerated
     * `Projectionist::getProjectors()` — the registration LIST — while dispatch
     * re-sorts on `getWeight()`, so it would have stayed green while the real
     * order diverged. Recording doubles would have had the same defect one level
     * down: they measure the order the DOUBLES ran.
     *
     * Nothing is substituted here. Each projector writes exactly one distinct
     * table, so the write sequence IS the dispatch sequence.
     *
     * Recorded honestly (P4): no ordering DEPENDENCY exists today — all four read
     * only the payload and write disjoint tables. The weights are insurance
     * against a future cross-read, and this row is what would notice them being
     * dropped.
     */
    #[Test]
    public function when_the_execute_pipeline_runs_then_the_projectors_fire_in_the_declared_order(): void
    {
        // Given a verified transfer
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);

        // When it is executed, with every query recorded
        DB::enableQueryLog();
        $this->executeTransfer($admin, $transfer->getKey(), self::executionBody())->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Then the four projectors wrote in weight order: 10, 20, 30, 40
        $this->assertSame(
            [
                'ownership_transfer_requests',      // OwnershipTransferProjector  w10
                'company_user',                     // MembershipProjector         w20
                'companies',                        // CompanyProjector            w30
                'ownership_transfer_pivot_history', // OwnershipTransferPivotHistoryProjector   w40
            ],
            $this->firstWriteOrderOf($log, [
                'ownership_transfer_requests',
                'company_user',
                'companies',
                'ownership_transfer_pivot_history',
            ]),
        );
    }

    /**
     * The pivot-history table is a DERIVED read model, so replaying its
     * projector must rebuild it — not duplicate it (ruling P2). That is the
     * deliberate inverse of `audit_trail`, an append-only compliance record
     * which must never rebuild (R5).
     *
     * Two things are asserted, and the second is the one that matters. Row count
     * proves the `(request_id, membership_id)` key held. But `old_role_id` still
     * reading the PRE-transfer role is what proves the projector took it off the
     * event payload: by replay time `MembershipProjector` has long since
     * overwritten `company_user.role_id`, so a projector reading the pivot would
     * quietly write `old_role_id == new_role_id` and destroy the only record of
     * the transition — while the row count stayed a perfect two.
     */
    #[Test]
    public function when_the_projection_is_replayed_then_the_pivot_history_is_rebuilt_not_duplicated(): void
    {
        // Given an executed transfer with its two history rows
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->anExecutedTransferStream($company, $owner, $admin);
        $target = User::query()->findOrFail($transfer->target_user_id);

        // When the pivot-history projector replays the whole stream
        Projectionist::replay(collect([app(OwnershipTransferPivotHistoryProjector::class)]));

        // Then the same two rows stand, with the prior roles still intact
        $this->assertPivotHistoryRecorded($transfer, $owner, $target);
    }

    /**
     * The identity of each history row, keyed by the pivot it describes.
     *
     * `updated_at` is deliberately absent: it records when the projection was
     * last written, so a replay moving it is honest. The three here must not
     * move — `id` is what a support ticket or a legal export cites,
     * `created_at` is when the record came into existence, and `executed_at` is
     * when ownership actually changed hands.
     *
     * @return array<string, array{id: string, created_at: string, executed_at: string}>
     */
    private function pivotHistoryIdentityOf(OwnershipTransferRequest $transfer): array
    {
        return DB::table('ownership_transfer_pivot_history')
            ->where('request_id', $transfer->getKey())
            ->get(['membership_id', 'id', 'created_at', 'executed_at'])
            ->keyBy('membership_id')
            ->map(fn (stdClass $row): array => [
                'id' => $row->id,
                'created_at' => $row->created_at,
                'executed_at' => $row->executed_at,
            ])
            ->all();
    }

    /**
     * A projector must be a deterministic function of the event stream (§2.15).
     * This one was not: `updateOrInsert` applies its whole `$values` array on the
     * UPDATE branch, and that array carried a freshly minted `Str::uuid7()` and
     * `created_at => now()`. So every projection rebuild re-keyed each row and
     * restamped its creation date.
     *
     * On this table specifically, because it is the forensic record of the role
     * transition — the migration's own docblock justifies its existence as
     * evidence "for a use case whose whole premise is legal defensibility". An id
     * cited in a support ticket six months earlier would stop resolving, and
     * `created_at` would claim the record was created on the day of the rebuild.
     *
     * The sibling replay row above cannot see it: it asserts row count and the
     * role pair, and both survive a re-key intact.
     */
    #[Test]
    public function when_the_projection_is_replayed_then_the_history_rows_keep_their_identity(): void
    {
        // Given an executed transfer whose two history rows have been written once
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->anExecutedTransferStream($company, $owner, $admin);
        $before = $this->pivotHistoryIdentityOf($transfer);

        // When the pivot-history projector replays the whole stream, a day later
        //
        // The time jump is what makes this falsifiable. Replayed in the same
        // second, a clock-sourced `created_at` or `executed_at` is byte-identical
        // to an event-sourced one and the assertion passes either way — which is
        // exactly how the original re-keying defect hid: `created_at` looked
        // stable while `id` churned.
        $this->travel(1)->days();
        Projectionist::replay(collect([app(OwnershipTransferPivotHistoryProjector::class)]));

        // Then each row is still the same row, created and executed when it was
        $this->assertEquals($before, $this->pivotHistoryIdentityOf($transfer));
    }

    /**
     * The scoping half of P5 (s149 C6). With only a held company in the fixture,
     * a guard that read `legal_hold` unscoped would pass the row above and freeze
     * every tenant on the platform — the failure is invisible until a SECOND,
     * unheld company is present to be executed.
     */
    #[Test]
    public function when_another_company_is_under_legal_hold_then_this_transfer_still_executes(): void
    {
        // Given a verified transfer, and an unrelated tenant that is frozen
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);
        [$heldCompany] = $this->aCompanyWithOwner();
        $this->placeCompanyUnderLegalHold($heldCompany);

        // When this company's transfer is executed
        $response = $this->executeTransfer($admin, $transfer->getKey(), self::executionBody());

        // Then the other tenant's hold does not reach it
        $response->assertOk();
        $this->assertSame(
            OwnershipTransferStatus::Executed,
            $this->currentTransfer($transfer)->status,
        );
    }

    /**
     * The T2 verification record survives T4 untouched, and its notes ride
     * FORWARD onto the executed event (ruling P3, OQ-1-146 / deferral D-c).
     *
     * Two distinct claims, and both matter for the same reason — UC-1-093's
     * premise is legal defensibility, so the chain must read "verified by X on
     * date D, then executed by Y" and stay readable afterwards:
     *
     * - The projection's verification columns are not overwritten. Execute
     *   re-validates rather than re-enters (P3), so it must not restamp them.
     * - `verificationNotes` appears on the `OwnershipTransferExecuted` payload,
     *   satisfying SM-1-05 §7 without a second capture. This is the assertion
     *   that would fail if someone "simplified" the event by dropping a field
     *   the Command no longer carries — the aggregate carries the notes forward
     *   from T2's event on the stream (ruling P18; the row after this one pins
     *   the sourcing).
     */
    #[Test]
    public function when_a_transfer_is_executed_then_the_verification_record_survives_unchanged(): void
    {
        // Given a verified transfer, and its verification record as it stands
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);
        $verified = $this->currentTransfer($transfer);

        // When it is executed
        $this->executeTransfer($admin, $transfer->getKey(), self::executionBody())->assertOk();

        // Then the verification columns read exactly as they did before
        $executed = $this->currentTransfer($transfer);
        $this->assertEquals(
            [$verified->verified_at, $verified->verified_by, $verified->verification_notes],
            [$executed->verified_at, $executed->verified_by, $executed->verification_notes],
        );

        // And the notes were carried forward onto the executed event itself
        $payload = LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferExecuted::class)
            ->where('aggregate_uuid', $transfer->getKey())
            ->sole()
            ->event_properties;

        $this->assertSame($verified->verification_notes, $payload['verificationNotes']);
    }

    /**
     * The projection can lag its own stream (the O1 class this file already
     * stages for the owner guard), and the notes are FORENSIC — they justify
     * the transition that moves legal authority. Sourced from the projection
     * they inherit its lag; sourced from the stream they are a pure function
     * of the events, immune to it. This row pins the stream as the source
     * (ruling P18, s162): T2's own recorded notes reach the executed payload
     * even when the projection row has dropped them.
     */
    #[Test]
    public function when_the_projection_drops_the_notes_then_the_executed_event_still_carries_them(): void
    {
        // Given a verified transfer whose projection row has lost its notes
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);
        $this->theProjectionDropsTheVerificationNotes($transfer);

        // When it is executed
        $this->executeTransfer($admin, $transfer->getKey(), self::executionBody())->assertOk();

        // Then the payload carries the notes T2 recorded on the stream
        $t2Notes = LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferIdentityVerified::class)
            ->where('aggregate_uuid', $transfer->getKey())
            ->sole()
            ->event_properties['verificationNotes'];
        $payload = LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferExecuted::class)
            ->where('aggregate_uuid', $transfer->getKey())
            ->sole()
            ->event_properties;

        $this->assertNotNull($t2Notes);
        $this->assertSame($t2Notes, $payload['verificationNotes']);
    }

    /**
     * A projection row that lost a T2 column while its stream kept it — the O1
     * divergence class, notes flavour. Named fixture helper per §2.13 (the
     * `theProjectionLagsBehindTheStream` precedent).
     */
    private function theProjectionDropsTheVerificationNotes(OwnershipTransferRequest $transfer): void
    {
        $transfer->forceFill(['verification_notes' => null])->save();
    }

    /**
     * The admin's stated reason survives, and the event payload is the ONLY
     * place it can.
     *
     * `ownership_transfer_requests.reason` holds the REQUESTER's reason from T1
     * and is not overwritten; `audit_trail` writes a static description with
     * `metadata => NULL` by the s149 ruling. So the one justification given for
     * moving legal authority lives in `stored_events` alone — and nothing read
     * it back, which meant `reason: ''` on the aggregate shipped green.
     *
     * UC-1-093 makes it mandatory at write time (error flow 4a) precisely
     * because a transfer executed without a recorded why is the thing a later
     * dispute cannot answer.
     */
    #[Test]
    public function when_a_transfer_is_executed_then_the_admins_reason_is_recorded_verbatim(): void
    {
        // Given a verified transfer
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);

        // When an allowlisted admin executes it with a stated reason
        $this->executeTransfer($admin, $transfer->getKey(), self::executionBody())->assertOk();

        // Then that reason is on the executed event, exactly as submitted
        $payload = LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferExecuted::class)
            ->where('aggregate_uuid', $transfer->getKey())
            ->sole()
            ->event_properties;

        $this->assertSame(self::executionBody()['reason'], $payload['reason']);
    }

    /**
     * Row 19a — the prompt itself, which must exist before its skip case can
     * mean anything (§S10b: the emergency contact is mandatory for owners, so
     * T4 hands the incoming owner that obligation; UC-1-029 collects it).
     *
     * This lap comes FIRST deliberately. Written the other way round, the skip
     * assertion would have passed vacuously against a codebase with no reactor
     * at all — nothing sends anything, so nothing is "skipped". That is the
     * born-green-for-the-wrong-reason shape this slice has already hit twice.
     */
    #[Test]
    public function when_the_new_owner_has_no_emergency_contact_then_they_are_prompted_to_set_one(): void
    {
        // Given a verified transfer whose target carries no emergency contact
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);
        $target = User::query()->findOrFail($transfer->target_user_id);

        // When it is executed
        $this->executeTransfer($admin, $transfer->getKey(), self::executionBody())->assertOk();

        // Then the incoming owner is prompted to supply one, in their new company
        //
        // Rendered rather than class-matched: `Notification::fake()` means a
        // class-only assertion never runs title()/body(), so the prompt's actual
        // wording — the thing that has to send an owner to UC-1-029 — would be
        // both unexecuted and unasserted.
        Notification::assertSentTo(
            $target,
            EmergencyContactRequiredNotification::class,
            fn (EmergencyContactRequiredNotification $notification): bool => $notification->toArray($target)['title'] === 'Add an emergency contact'
                && str_contains($notification->toArray($target)['body'], $company->name),
        );
    }

    /**
     * Row 19 — the short-circuit (SD-1-02 OQ #3). The common case, since T1
     * already refuses a target without a contact; prompting anyway would train
     * owners to ignore the notice.
     *
     * Only meaningful because 19a exists. Against a codebase with no reactor
     * this assertion passes while proving nothing, which is why the prompt was
     * built first.
     */
    #[Test]
    public function when_the_new_owner_already_has_an_emergency_contact_then_the_wizard_prompt_is_skipped(): void
    {
        // Given a verified transfer whose target already carries a contact
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);
        $target = User::query()->findOrFail($transfer->target_user_id);
        CompanyUser::query()
            ->where('company_id', $transfer->company_id)
            ->where('user_id', $target->getKey())
            ->update(['emergency_contact_name' => 'Rani Perera']);

        // When it is executed
        $this->executeTransfer($admin, $transfer->getKey(), self::executionBody())->assertOk();

        // Then they are not asked for something they have already given
        Notification::assertNotSentTo($target, EmergencyContactRequiredNotification::class);
    }

    /**
     * Ownership actually moved, across all three read-model surfaces the swap
     * touches. Four aspects, because any one alone is satisfiable by a partial
     * write — and a partial write is precisely the state SD-1-02 §2.2 exists to
     * make impossible.
     */
    private function assertOwnershipMoved(
        OwnershipTransferRequest $transfer,
        User $outgoingOwner,
        User $incomingOwner,
    ): void {
        $current = $this->currentTransfer($transfer);

        $this->assertSame(OwnershipTransferStatus::Executed, $current->status);
        // The SLA timer is cleared, not merely ignored: a terminal row left with
        // a live deadline is exactly what the expiry scan would pick up again.
        $this->assertNull($current->next_state_transition_at);
        $this->assertSame(
            $incomingOwner->getKey(),
            Company::query()->whereKey($transfer->company_id)->value('owner_id'),
        );
        $this->assertSame(
            ['admin', 'owner'],
            [
                $this->pivotRoleCodeOf($transfer->company_id, $outgoingOwner),
                $this->pivotRoleCodeOf($transfer->company_id, $incomingOwner),
            ],
        );
    }

    /**
     * WHO moved ownership, WHEN, and BETWEEN WHOM — the forensic quad on the
     * transfer row (OQ-1-044).
     *
     * These four columns are all `nullable()`, so every one of them could be
     * dropped from the projector's update and the row would still save. Until
     * this helper existed nothing read them back after a T4: the whole quad
     * could be deleted, or the owner pair written BACKWARDS, and the suite stayed
     * green. On an append-only record whose stated purpose is to document the
     * swap that ACTUALLY happened, a silently reversed pair is worse than an
     * absent one — it reads as evidence.
     *
     * Derives rather than snapshots, the `assertNoWriteSurvived` idiom: the
     * pre-transfer parties ARE `current_owner_user_id` and `target_user_id` on
     * the transfer row, so the helper stays at two parameters (§2.2 Rule 3).
     */
    private function assertExecutionStamped(OwnershipTransferRequest $transfer, User $executedBy): void
    {
        $current = $this->currentTransfer($transfer);

        $this->assertSame(
            [$transfer->current_owner_user_id, $transfer->target_user_id],
            [$current->old_owner_user_id, $current->new_owner_user_id],
        );
        $this->assertSame($executedBy->getKey(), $current->executed_by);
        $this->assertNotNull($current->executed_at);
    }

    /**
     * The role transition is RECORDED, not merely inferable (ruling P2). The
     * old_role_id assertions are the only ones in this class that can catch a
     * projector reading the prior role from company_user instead of the event
     * payload — by the time it runs, MembershipProjector has already overwritten
     * that row, so such a projector writes old_role_id == new_role_id.
     */
    private function assertPivotHistoryRecorded(
        OwnershipTransferRequest $transfer,
        User $outgoingOwner,
        User $incomingOwner,
    ): void {
        $rows = DB::table('ownership_transfer_pivot_history')
            ->where('request_id', $transfer->getKey())
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(
            [
                [$this->seededRole('owner')->getKey(), $this->seededRole('admin')->getKey()],
                [$this->seededRole('employee')->getKey(), $this->seededRole('owner')->getKey()],
            ],
            [
                $this->roleTransitionRecordedFor($rows, $outgoingOwner),
                $this->roleTransitionRecordedFor($rows, $incomingOwner),
            ],
        );
        // Both parties carry the transfer row's own execution instant — one
        // transfer, one moment, sourced from the event rather than from whenever
        // each projector happened to run.
        //
        // This replaced `assertCount(1, ...->unique())`, which could not fail:
        // the projector hoists `$executedAt` once and passes the SAME variable
        // into both rows, so the pair is identical by construction. Even the
        // mutation it named — a per-row `now()` — left both values in the same
        // second on a `timestamp` column. Compared against the transfer row, a
        // clock-sourced value diverges the moment the two are not simultaneous,
        // which the replay row then proves outright.
        $this->assertSame(
            [$this->currentTransfer($transfer)->executed_at?->toDateTimeString()],
            $rows->pluck('executed_at')->unique()->values()->all(),
        );
    }

    /**
     * The append-only audit row for the transition, linked to its stored event.
     *
     * UC-1-093's Minimal Guarantee is that no role swap succeeds without a
     * corresponding audit record, and T4 is the transition that actually moves
     * legal authority — so this row is the evidence a later "that transfer was
     * unauthorised" claim turns on (UC-1-093 stakeholders: Lesily Legal).
     *
     * The COUNT is an aspect, not decoration: the stream is T1 → T2 → T4, so
     * three rows means execute added exactly one. Without it, a reactor that
     * wrote two rows — or that wrote none while some other arm happened to
     * satisfy the column map — would pass.
     *
     * Written as ONE assertDatabaseHas carrying the whole row plus the two
     * null-shape checks a column map cannot express, which is the shape
     * assertRejectionAudited was collapsed to at the s152 review (§2.2 Rule 5).
     *
     * metadata stays NULL per the s149 ruling, and the request-context columns
     * are pinned in BOTH directions: an event-sourced write has no HTTP request
     * behind it, unlike the sibling READ rows which pin them to real values.
     */
    private function assertExecutionAudited(OwnershipTransferRequest $transfer, User $admin): void
    {
        // Scoped to THIS transfer's subject, not the whole table — a global
        // count holds only while every other fixture logs nothing, the exact
        // shape the sibling class retired at its s161 retrofit (s162).
        $this->assertSame(3, AuditEntry::query()
            ->where('subject_id', $transfer->getKey())
            ->count());
        $this->assertDatabaseHas('audit_trail', [
            'event_class' => 'OwnershipTransferExecuted',
            'subject_type' => 'ownership_transfer_request',
            'subject_id' => $transfer->getKey(),
            'subject_label' => 'Ownership transfer request',
            'company_id' => $transfer->company_id,
            'action' => 'transfer_executed',
            'description' => 'Platform admin executed the ownership transfer; ownership moved to the new owner.',
            'actor_user_id' => $admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $admin->name,
            'request_id' => null,
            'ip_address' => null,
            'user_agent' => null,
            'severity' => 'info',
        ]);

        $entry = AuditEntry::query()
            ->where('event_class', 'OwnershipTransferExecuted')
            ->where('subject_id', $transfer->getKey())
            ->sole();

        $this->assertNotNull($entry->stored_event_id);
        $this->assertNull($entry->metadata);
    }

    /**
     * A second company owned by the same user — the class's one cross-company
     * fixture. Named fixture helper per §2.13 (inline attribute overrides live
     * in fixtures, never test bodies; the `theOwnerChangesTo` precedent).
     */
    private function aSecondCompanyOwnedBy(User $owner): Company
    {
        $company = Company::factory()->create(['owner_id' => $owner->getKey()]);
        $company->users()->attach($owner, ['role_id' => $this->seededRole('owner')->getKey()]);

        return $company;
    }

    private function pivotRoleCodeOf(string $companyId, User $user): ?string
    {
        return DB::table('company_user')
            ->join('roles', 'roles.id', '=', 'company_user.role_id')
            ->where('company_user.company_id', $companyId)
            ->where('company_user.user_id', $user->getKey())
            ->whereNull('company_user.deleted_at')
            ->value('roles.code');
    }

    /**
     * The recorded old → new role pair for $user, or a null pair when no row was
     * written for them at all — which is a failure the CALLER's assertion
     * reports in full, rather than an error thrown from in here.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return array{0: ?string, 1: ?string}
     */
    private function roleTransitionRecordedFor(Collection $rows, User $user): array
    {
        /** @var array<string, mixed> $row */
        $row = (array) $rows->firstWhere('user_id', $user->getKey());

        return [$row['old_role_id'] ?? null, $row['new_role_id'] ?? null];
    }

    #[Test]
    public function when_a_platform_admin_executes_a_verified_transfer_then_ownership_moves_atomically(): void
    {
        // Given a verified transfer whose owner and target are both real members
        [$company, $owner] = $this->aCompanyWithOwner();
        $admin = $this->anAllowlistedAdmin();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $admin);
        $target = User::query()->findOrFail($transfer->target_user_id);

        // When an allowlisted admin executes it
        $response = $this->executeTransfer($admin, $transfer->getKey(), self::executionBody());

        // Then every write surface moved together
        $response->assertOk();
        $response->assertJsonPath('data.status', 'executed');
        $this->assertOwnershipMoved($transfer, $owner, $target);
        $this->assertPivotHistoryRecorded($transfer, $owner, $target);
        // The forensic quad: who moved it, when, and between whom. Nullable
        // columns nothing else reads back after a T4.
        $this->assertExecutionStamped($transfer, $admin);
        // UC-1-093's Minimal Guarantee: no role swap succeeds without a
        // corresponding audit record. T4 is the transition that moves legal
        // authority, so this is the one row a disputed transfer turns on.
        $this->assertExecutionAudited($transfer, $admin);
        // Amendment R: the read-model oracle proves the VALUE, never the
        // mechanism. Without this, a direct write to the projected columns
        // passes every assertion above and is silently reverted on replay.
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferExecuted::class)
            ->count());
        // Both parties are told, separately, and each is told the RIGHT thing.
        //
        // The closures render the notification, which is the only thing that
        // executes title()/body() at all: Notification::fake() in the base means
        // a class-only assertSentTo never invokes a channel method, so the copy a
        // user actually reads would go unexecuted and unasserted (the s152
        // finding, and what codecov/patch caught on the first push of this PR).
        //
        // Each pins the company NAME, not just a static phrase — an empty or
        // wrong tenant name passing a substring check is the specific regression
        // the s152 review found in the sibling module.
        //
        // UC-1-093's stakeholder list makes this the outgoing owner's ONLY
        // signal that they no longer hold the company: they initiated the
        // request, but an admin chose the moment.
        Notification::assertSentTo(
            $owner,
            OwnershipTransferredAwayNotification::class,
            fn (OwnershipTransferredAwayNotification $notification): bool => $notification->toArray($owner)['title'] === 'Ownership transferred'
                && str_contains($notification->toArray($owner)['body'], $company->name)
                && str_contains($notification->toArray($owner)['body'], 'no longer the owner'),
        );
        // The incoming owner gets their own notice, carrying a different fact.
        // Distinct from the emergency-contact prompt, which is conditional —
        // this one always fires.
        Notification::assertSentTo(
            $target,
            OwnershipReceivedNotification::class,
            fn (OwnershipReceivedNotification $notification): bool => $notification->toArray($target)['title'] === 'You are now the owner'
                && str_contains($notification->toArray($target)['body'], $company->name),
        );
    }
}
