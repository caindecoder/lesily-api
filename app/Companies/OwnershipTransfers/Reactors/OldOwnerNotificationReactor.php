<?php

namespace App\Companies\OwnershipTransfers\Reactors;

use App\Models\User;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExecuted;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferredAwayNotification;

/**
 * SM-1-05 T4 — tells the OUTGOING owner the transfer completed.
 *
 * Its own class rather than a branch inside a shared reactor, matching the
 * module's existing split (requester / verifier): each reactor answers one
 * question about one recipient, which is what let the s155 review prove the
 * verifier filter from both sides instead of arguing about it.
 *
 * The old owner is read from the EVENT payload, not the projection. Either
 * would work today — this event's own projector runs first — but the payload
 * carries the forensic pair deliberately (OQ-1-044), and reading it keeps the
 * reactor independent of whether a later change reprojects the row.
 *
 * Synchronous, like the module's other reactors; Task R2 owns the queueing
 * conformance pass, and the CD already specifies `Queued.` for this class.
 */
class OldOwnerNotificationReactor extends Reactor
{
    public function onOwnershipTransferExecuted(OwnershipTransferExecuted $event): void
    {
        $transfer = OwnershipTransferRequest::query()->findOrFail($event->requestId);
        $outgoingOwner = User::query()->findOrFail($event->oldOwnerUserId);

        $outgoingOwner->notify(new OwnershipTransferredAwayNotification($transfer));
    }
}
