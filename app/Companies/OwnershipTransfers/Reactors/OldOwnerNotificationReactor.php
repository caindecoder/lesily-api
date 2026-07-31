<?php

namespace App\Companies\OwnershipTransfers\Reactors;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
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
 * Queued (ShouldQueue, Task R2 — the CD specified `Queued.` for this class
 * from the start). Delivery-time staleness is benign: the recipient comes
 * from the immutable event payload, and the row the notification renders is
 * terminal `executed` — no built flow moves it again.
 */
class OldOwnerNotificationReactor extends Reactor implements ShouldQueue
{
    public function onOwnershipTransferExecuted(OwnershipTransferExecuted $event): void
    {
        $transfer = OwnershipTransferRequest::query()->findOrFail($event->requestId);
        $outgoingOwner = User::query()->findOrFail($event->oldOwnerUserId);

        $outgoingOwner->notify(new OwnershipTransferredAwayNotification($transfer));
    }
}
