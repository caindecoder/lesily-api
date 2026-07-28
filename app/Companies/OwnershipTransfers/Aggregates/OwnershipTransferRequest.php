<?php

namespace App\Companies\OwnershipTransfers\Aggregates;

use Carbon\CarbonImmutable;
use App\Foundation\EventStore\AggregateRoot;
use App\Companies\OwnershipTransfers\Enums\TransitionSource;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExpired;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRejected;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferCancelled;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRequested;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferIdentityVerified;
use App\Companies\OwnershipTransfers\Exceptions\OpenOwnershipTransferExistsException;
use App\Companies\OwnershipTransfers\UseCases\Commands\ExpireOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\UseCases\Commands\RejectOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\UseCases\Commands\RequestOwnershipTransferCommand;
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
 * · expireIfStale (T8/T9). Still to come: execute (T4, Task 8).
 *
 * One transition refuses differently from the rest: `expireIfStale()` no-ops
 * where the others throw, because its caller is a scheduler with nobody to hand
 * a 409 to (ruling M1). The reasoning lives on the method.
 */
class OwnershipTransferRequest extends AggregateRoot
{
    private ?OwnershipTransferStatus $status = null;

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
     * Both refusals answer the same hazard, from opposite ends. The scan reads
     * the row's state from the PROJECTION to select it, then this method reads it
     * again by replaying the STREAM — two tables, two instants. Anything that
     * moves the row in between makes the selection stale:
     *
     *  - it left the open set entirely (withdrawn, rejected, already expired) —
     *    the `default` arm; or
     *  - it moved to the OTHER open state, which is the sharper case, because
     *    the row now carries a fresh deadline and is not due at all. An admin
     *    verifying at day 29 gives the transfer 14 new days; expiring it then
     *    would fire 14 days early AND stamp `verified-stage` with the 30-day
     *    figure the interactor derived before the move (ruling O1).
     *
     * So the Command carries what the scan saw and any disagreement refuses.
     * That is what UC-1-116 error flow 4b asks for ("no event emitted"), and
     * SM-1-05 §4 states both guards as state STILL `Requested` / STILL
     * `Verified` — still, not merely open.
     *
     * The `default` arm stays reachable only by driving this method directly,
     * the shape `ExpireTrialsCommandTest` uses for its own console-unreachable
     * no-op arms. Note what the repository predicate does and does not do: it
     * excludes rows whose timer is NULL, which covers terminal rows only because
     * a different class — the projector — clears the timer in every terminal arm.
     * It is not a state filter.
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
            expiredAt: CarbonImmutable::now(),
        ));
    }

    public function applyOwnershipTransferExpired(OwnershipTransferExpired $event): void
    {
        $this->status = OwnershipTransferStatus::Expired;
    }
}
