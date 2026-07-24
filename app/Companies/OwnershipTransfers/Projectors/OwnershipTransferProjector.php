<?php

namespace App\Companies\OwnershipTransfers\Projectors;

use Carbon\CarbonImmutable;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRequested;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferIdentityVerified;

/**
 * Sole writer of ownership_transfer_requests (ERD-1-05). The T1 insert carries
 * the 30-day next_state_transition_at anchor in the same write — no
 * update-after-insert ordering dependency; the designed TimerProjector joins
 * when its first reset/clear behavior lands (verify task, UC-1-113).
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
}
