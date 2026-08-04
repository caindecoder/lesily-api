<?php

namespace App\Companies\OwnershipTransfers\Projectors;

use Carbon\CarbonImmutable;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRejected;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferCancelled;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRequested;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferIdentityVerified;

/**
 * Sole writer of ownership_transfer_requests (ERD-1-05), including the whole
 * next_state_transition_at lifecycle: the T1 insert carries the 30-day anchor in
 * the same write, T2 resets it to the 14-day window in the same update as the
 * verification columns, and each terminal transition clears it alongside its own
 * columns. The separately designed OwnershipTransferTimerProjector is therefore
 * SUPERSEDED and never built (s149 ruling G3) — one writer per table.
 */
class OwnershipTransferProjector extends Projector
{
    public function onOwnershipTransferRequested(OwnershipTransferRequested $event): void
    {
        $requestedAt = CarbonImmutable::instance($event->requestedAt);

        OwnershipTransferRequest::query()->create([
            'id' => $event->requestId,
            'company_id' => $event->companyId,
            'current_owner_user_id' => $event->currentOwnerUserId,
            'target_user_id' => $event->targetUserId,
            'status' => OwnershipTransferStatus::Requested,
            'request_channel' => $event->requestChannel,
            'reason' => $event->reason,
            'requested_at' => $requestedAt,
            'next_state_transition_at' => $requestedAt->addDays(
                (int) config('ownership-transfers.request_ttl_days', 30),
            ),
            'created_by' => $event->currentOwnerUserId,
        ]);
    }

    /**
     * SM-1-05 T2. The verification record and the timer reset land in the SAME
     * update: this projector stays the table's sole writer, so the designed
     * OwnershipTransferTimerProjector is superseded rather than built (s149
     * ruling G3, extending the T1 anchor-in-insert decision above).
     */
    public function onOwnershipTransferIdentityVerified(OwnershipTransferIdentityVerified $event): void
    {
        $verifiedAt = CarbonImmutable::instance($event->verifiedAt);

        OwnershipTransferRequest::query()->whereKey($event->requestId)->update([
            'status' => OwnershipTransferStatus::Verified,
            'verified_at' => $verifiedAt,
            'verified_by' => $event->verifiedBy,
            'verification_notes' => $event->verificationNotes,
            'verification_artefacts' => $event->verificationArtefacts,
            'next_state_transition_at' => $verifiedAt->addDays(
                (int) config('ownership-transfers.verified_ttl_days', 14),
            ),
            'updated_by' => $event->verifiedBy,
        ]);
    }

    /**
     * SM-1-05 T3 + T5. Terminal, so the rejection record and the SLA-timer clear
     * land in the SAME update — this projector remains the table's sole writer
     * (ruling G3), and a terminal row carries no next deadline by definition.
     */
    public function onOwnershipTransferRejected(OwnershipTransferRejected $event): void
    {
        OwnershipTransferRequest::query()->whereKey($event->requestId)->update([
            'status' => OwnershipTransferStatus::Rejected,
            'rejected_at' => CarbonImmutable::instance($event->rejectedAt),
            'rejected_by' => $event->rejectedBy,
            'rejection_reason' => $event->rejectionReason,
            'rejection_reason_code' => $event->rejectionReasonCode,
            'rejection_source' => $event->source,
            'next_state_transition_at' => null,
            'updated_by' => $event->rejectedBy,
        ]);
    }

    /**
     * SM-1-05 T6 + T7. Terminal, so the withdrawal record and the SLA-timer clear
     * land in the SAME update — this projector remains the table's sole writer
     * (ruling G3), and a terminal row carries no next deadline by definition.
     *
     * The two windows clear DIFFERENT values: T6 drops `requested_at + 30d`, T7
     * drops `verified_at + 14d`. Both are asserted, because at s153 the reject
     * twin turned out to have only ever pinned one of its two.
     */
    public function onOwnershipTransferCancelled(OwnershipTransferCancelled $event): void
    {
        OwnershipTransferRequest::query()->whereKey($event->requestId)->update([
            'status' => OwnershipTransferStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::instance($event->cancelledAt),
            'cancelled_by' => $event->cancelledBy,
            'cancellation_reason' => $event->reason,
            'cancellation_source' => $event->source,
            'next_state_transition_at' => null,
            'updated_by' => $event->cancelledBy,
        ]);
    }
}
