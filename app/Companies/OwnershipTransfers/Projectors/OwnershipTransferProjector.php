<?php

namespace App\Companies\OwnershipTransfers\Projectors;

use Carbon\CarbonImmutable;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRequested;

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
}
