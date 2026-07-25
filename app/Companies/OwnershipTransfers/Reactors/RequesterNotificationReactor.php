<?php

namespace App\Companies\OwnershipTransfers\Reactors;

use App\Models\User;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRejected;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferRejectedNotification;

/**
 * Tells the ORIGINAL REQUESTER that their transfer ended, and why (SM-1-05 §7 —
 * RequesterNotificationReactor listens to Rejected and Expired; T7/T9 join it at
 * their own tasks).
 *
 * Only the requester is notified. The designated new owner is deliberately not:
 * a rejection lands before any role change, so they were never affected, and
 * telling them a transfer they may not have known about was declined leaks a
 * decision that was never theirs (UC-1-128 stakeholders).
 *
 * Reading the transfer row here is safe despite amendment N: T3/T5 is a
 * single-aggregate write and this event's own projector runs before its reactors,
 * so the row is committed and current by the time we look.
 */
class RequesterNotificationReactor extends Reactor
{
    public function onOwnershipTransferRejected(OwnershipTransferRejected $event): void
    {
        $transfer = OwnershipTransferRequest::query()->findOrFail($event->requestId);
        $requester = User::query()->findOrFail($transfer->current_owner_user_id);
        $actor = User::query()->findOrFail($event->rejectedBy);

        $requester->notify(new OwnershipTransferRejectedNotification(
            $transfer,
            $actor,
            $event->rejectionReason,
        ));
    }
}
