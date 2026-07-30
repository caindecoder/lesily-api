<?php

namespace App\Companies\OwnershipTransfers\Aggregates;

use Carbon\CarbonImmutable;
use App\Foundation\EventStore\AggregateRoot;
use App\Companies\OwnershipTransfers\Enums\TransitionSource;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\UseCases\Values\OwnershipSwap;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExpired;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExecuted;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRejected;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferCancelled;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRequested;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferIdentityVerified;
use App\Companies\OwnershipTransfers\Exceptions\OpenOwnershipTransferExistsException;
use App\Companies\OwnershipTransfers\UseCases\Commands\ExpireOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\UseCases\Commands\RejectOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\UseCases\Commands\ExecuteOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\UseCases\Commands\RequestOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\Exceptions\OwnershipTransferNotExecutableException;
use App\Companies\OwnershipTransfers\Exceptions\OwnershipTransferNotRejectableException;
use App\Companies\OwnershipTransfers\Exceptions\OwnershipTransferNotVerifiableException;
use App\Companies\OwnershipTransfers\UseCases\Commands\WithdrawOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\Exceptions\OwnershipTransferNotWithdrawableException;
use App\Companies\OwnershipTransfers\UseCases\Commands\VerifyOwnershipTransferIdentityCommand;

/**
 * The transfer-request lifecycle stream (SM-1-05): one aggregate per request,
 * multi-actor (Owner requests → support verifies → platform admin executes →
 * system expires). Unlike the guardless Memberships methods, transitions here
 * carry aggregate state guards (E28).
 *
 * Built: request (T1) · verifyIdentity (T2) · reject (T3/T5) · withdraw (T6/T7)
 * · expireIfStale (T8/T9) · execute (T4).
 *
 * execute() is the only transition taking a second argument: its event carries
 * membership and role ids that no aggregate can resolve, since reading them
 * means reading another aggregate's read model. The interactor resolves them
 * from live state and hands them over as an OwnershipSwap.
 *
 * One transition refuses differently from the rest: `expireIfStale()` no-ops
 * where the others throw, because its caller is a scheduler with nobody to hand
 * a 409 to (ruling M1). The reasoning lives on the method.
 */
class OwnershipTransferRequest extends AggregateRoot
{
    private ?OwnershipTransferStatus $status = null;

    /**
     * Captured at T2 so T4 can carry it forward as a pure function of the
     * STREAM (ruling P18, s162). Reading it from the projection instead made
     * the one forensic field on the executed payload inherit projection lag —
     * the O1 divergence class the owner guard already refuses to trust.
     */
    private ?string $verificationNotes = null;

    /**
     * SM-1-05 T1 genesis. The re-fire guard is Tier-2 of the one-open-request
     * invariant — the interactor's projection read (Tier-1) covers the
     * cross-stream case; this guard keeps a replayed or duplicated stream from
     * recording a second genesis.
     */
    public function request(RequestOwnershipTransferCommand $command): static
    {
        if ($this->status !== null) {
            throw new OpenOwnershipTransferExistsException;
        }

        return $this->forCompany($command->companyId)
            ->recordThat(new OwnershipTransferRequested(
                requestId: $this->uuid(),
                companyId: $command->companyId,
                currentOwnerUserId: $command->currentOwnerUserId,
                targetUserId: $command->targetUserId,
                reason: $command->reason,
                requestChannel: $command->requestChannel,
                requestedAt: CarbonImmutable::now(),
            ));
    }

    public function applyOwnershipTransferRequested(OwnershipTransferRequested $event): void
    {
        $this->status = OwnershipTransferStatus::Requested;
    }

    /**
     * SM-1-05 T2. The ONE invariant this aggregate can see on its own stream:
     * only a Requested transfer can be verified, so a re-verify and every
     * terminal-state attempt are refused here (E28 — enumerate the transitions,
     * refuse the rest). The parent company's legal hold is NOT checked here: it
     * belongs to another aggregate and this one never reads the database, so the
     * interactor guards it (LD ApproveLeaveAction holds its cross-entity guard
     * the same way).
     */
    public function verifyIdentity(VerifyOwnershipTransferIdentityCommand $command): static
    {
        if ($this->status !== OwnershipTransferStatus::Requested) {
            throw new OwnershipTransferNotVerifiableException;
        }

        return $this->recordThat(new OwnershipTransferIdentityVerified(
            requestId: $this->uuid(),
            verifiedBy: $command->verifiedBy,
            verificationNotes: $command->verificationNotes,
            verificationArtefacts: $command->verificationArtefacts,
            verifiedAt: CarbonImmutable::now(),
        ));
    }

    public function applyOwnershipTransferIdentityVerified(OwnershipTransferIdentityVerified $event): void
    {
        $this->status = OwnershipTransferStatus::Verified;
        $this->verificationNotes = $event->verificationNotes;
    }

    /**
     * SM-1-05 T3 + T5. One method, two windows: the pre-transition state decides
     * the `source` discriminator, so the caller never has to know which window it
     * is in (the shared-endpoint shape the API doc locks).
     *
     * No legal-hold check here or in the interactor: §S10b's hold freezes
     * ownership CHANGES, and a rejection changes none (s151 ruling H4) — the
     * deliberate asymmetry with verifyIdentity() above.
     */
    public function reject(RejectOwnershipTransferCommand $command): static
    {
        // The transition table IS the guard (E28 §5 — enumerate the transitions,
        // refuse the rest). Deriving `source` and refusing an illegal state are
        // the same question asked once: only the two OPEN states are rejectable,
        // and each names its own window. A separate `if` before this would have
        // to repeat the same state knowledge and could drift from it.
        $source = match ($this->status) {
            OwnershipTransferStatus::Requested => TransitionSource::PreVerification,
            OwnershipTransferStatus::Verified => TransitionSource::ExecutionTime,
            default => throw new OwnershipTransferNotRejectableException,
        };

        return $this->recordThat(new OwnershipTransferRejected(
            requestId: $this->uuid(),
            rejectedBy: $command->rejectedBy,
            rejectionReason: $command->reason,
            rejectionReasonCode: $command->rejectionReasonCode,
            source: $source->value,
            rejectedAt: CarbonImmutable::now(),
        ));
    }

    public function applyOwnershipTransferRejected(OwnershipTransferRejected $event): void
    {
        $this->status = OwnershipTransferStatus::Rejected;
    }

    /**
     * SM-1-05 T6 + T7. The requester's own exit, mirroring reject()'s two-window
     * shape: the pre-transition state decides the `source`, so the caller never
     * has to know which window it is in.
     *
     * No legal-hold check, for the same reason reject() has none (s151 H4):
     * §S10b's hold freezes ownership CHANGES, and a withdrawal changes none. The
     * ACTOR check is not here either — `current_owner_user_id` is answerable from
     * the projection without replaying a stream, so the interactor holds it.
     */
    public function withdraw(WithdrawOwnershipTransferCommand $command): static
    {
        // The transition table IS the guard (E28 §5 — enumerate the transitions,
        // refuse the rest). Deriving `source` and refusing an illegal state are
        // the same question asked once: only the two OPEN states are
        // withdrawable, and each names its own window.
        $source = match ($this->status) {
            OwnershipTransferStatus::Requested => TransitionSource::PreVerification,
            OwnershipTransferStatus::Verified => TransitionSource::PostVerification,
            default => throw new OwnershipTransferNotWithdrawableException,
        };

        return $this->recordThat(new OwnershipTransferCancelled(
            requestId: $this->uuid(),
            cancelledBy: $command->cancelledBy,
            reason: $command->reason,
            source: $source->value,
            cancelledAt: CarbonImmutable::now(),
        ));
    }

    public function applyOwnershipTransferCancelled(OwnershipTransferCancelled $event): void
    {
        $this->status = OwnershipTransferStatus::Cancelled;
    }

    /**
     * SM-1-05 T8 + T9. The system's own exit, and the ONLY transition here that
     * refuses by doing nothing rather than by throwing (ruling M1).
     *
     * The asymmetry is deliberate. Every other method answers a human holding an
     * HTTP request, so a `STATE_GUARD_VIOLATION` 409 reaches somebody who can
     * act on it. This one answers a scheduler: there is no caller to inform, and
     * a throw inside the batch loop would force a choice between aborting the
     * remaining rows and swallowing the exception — the silent-failure shape
     * OQ-1-141 was deferred over. Both UC bodies say "SKIPS the transition" in
     * terms, and both built system scans agree (`Subscription::endTrial` returns
     * unchanged; `UserMembership::expireIfPending` has no guard at all).
     *
     * The no-op is a WRITTEN match arm, not an absent check: E28 requires every
     * state x event combination to be explicitly handled, and "deliberately does
     * nothing" qualifies where "never considered" does not.
     *
     * The scan reads the row's state from the PROJECTION to select it, then this
     * method reads it again by replaying the STREAM — two tables, two instants.
     * Anything that moves the row in between makes the selection stale, and the
     * `expectedStatus` guard refuses ALL of it: a move out of the open set
     * (withdrawn, rejected, already expired) and a move to the OTHER open state
     * alike. The second is the sharper case, because the row then carries a FRESH
     * deadline and is not due at all — an admin verifying at day 29 gives the
     * transfer 14 new days, and expiring it would fire 14 days early while
     * stamping `verified-stage` with the 30-day figure the interactor derived
     * before the move (ruling O1).
     *
     * So the Command carries what the scan saw and any disagreement refuses.
     * That is what UC-1-116 error flow 4b asks for ("no event emitted"), and
     * SM-1-05 §4 states both guards as state STILL `Requested` / STILL
     * `Verified` — still, not merely open.
     *
     * The `match`'s `default` arm therefore answers NEITHER production case: by
     * the time it is evaluated, the replayed state already equals the selected
     * one. It is reachable only by a caller that SELECTED a terminal state, which
     * the repository cannot do (it selects `openStates()`) — so only a direct
     * drive reaches it, the shape `ExpireTrialsCommandTest` uses for its own
     * console-unreachable no-op arms. It stays written because E28 wants every
     * state × event combination explicitly handled, and because deleting it would
     * leave a `match` Larastan requires to cover all six cases.
     *
     * Do not read the two refusals as redundant: removing the `expectedStatus`
     * guard does not fall through to `default`, it falls through to the
     * `Requested`/`Verified` arms and records the spurious expiry O1 exists to
     * prevent.
     */
    public function expireIfStale(ExpireOwnershipTransferCommand $command): static
    {
        if ($this->status !== $command->expectedStatus) {
            return $this;
        }

        $source = match ($this->status) {
            OwnershipTransferStatus::Requested => TransitionSource::RequestedStage,
            OwnershipTransferStatus::Verified => TransitionSource::VerifiedStage,
            default => null,
        };

        if ($source === null) {
            return $this;
        }

        return $this->recordThat(new OwnershipTransferExpired(
            requestId: $this->uuid(),
            source: $source->value,
            expiredAfterDays: $command->expiredAfterDays,
            expiredAt: $command->expiredAt,
        ));
    }

    public function applyOwnershipTransferExpired(OwnershipTransferExpired $event): void
    {
        $this->status = OwnershipTransferStatus::Expired;
    }

    /**
     * The state guard, callable on its own so the interactor can ask this
     * question BEFORE doing any cross-entity work.
     *
     * That ordering is the point. Resolving the swap first means a closed
     * transfer whose target has since left the company answers *"the target is
     * not a member"* — true, but a consequence of time passing rather than the
     * reason the execution failed. The transfer being closed is the useful
     * answer, and only this aggregate can give it.
     *
     * `execute()` still calls it, so a caller that drives the aggregate directly
     * is guarded exactly as before.
     */
    public function guardIsExecutable(): void
    {
        if ($this->status !== OwnershipTransferStatus::Verified) {
            throw new OwnershipTransferNotExecutableException;
        }
    }

    /**
     * SM-1-05 T4. The ONE invariant this aggregate can see on its own stream:
     * only a Verified transfer can be executed — guardIsExecutable() refuses a
     * re-execute and every other source state (E28 — enumerate the transitions,
     * refuse the rest).
     *
     * The three cross-entity guards are NOT here and cannot be: the live owner
     * (companies.owner_id), the target's live membership, and the parent
     * company's legal hold all belong to other aggregates, and this one never
     * reads the database. The interactor holds all three (rulings P3 and P5,
     * following B1 and the LD ApproveLeaveAction shape).
     */
    public function execute(ExecuteOwnershipTransferCommand $command, OwnershipSwap $swap): static
    {
        $this->guardIsExecutable();

        return $this->recordThat(new OwnershipTransferExecuted(
            requestId: $this->uuid(),
            companyId: $swap->companyId,
            oldOwnerUserId: $swap->oldOwnerUserId,
            newOwnerUserId: $swap->newOwnerUserId,
            oldOwnerMembershipId: $swap->oldOwnerMembershipId,
            oldOwnerOldRoleId: $swap->oldOwnerOldRoleId,
            oldOwnerNewRoleId: $swap->oldOwnerNewRoleId,
            newOwnerMembershipId: $swap->newOwnerMembershipId,
            newOwnerOldRoleId: $swap->newOwnerOldRoleId,
            newOwnerNewRoleId: $swap->newOwnerNewRoleId,
            executedBy: $command->executedBy,
            reason: $command->reason,
            verificationNotes: $this->verificationNotes,
            executedAt: CarbonImmutable::now(),
        ));
    }

    public function applyOwnershipTransferExecuted(OwnershipTransferExecuted $event): void
    {
        $this->status = OwnershipTransferStatus::Executed;
    }
}
