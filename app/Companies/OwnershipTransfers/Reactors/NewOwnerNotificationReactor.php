<?php

namespace App\Companies\OwnershipTransfers\Reactors;

use App\Models\User;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExecuted;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipReceivedNotification;

/**
 * SM-1-05 T4 — tells the INCOMING owner the role has landed.
 *
 * Deliberately separate from `EmergencyContactWizardReactor`, which also
 * notifies this same user: that one is CONDITIONAL on the contact being absent,
 * so it cannot double as the announcement. Folding them together would mean an
 * owner who already had a contact heard nothing at all — and would put two
 * unrelated change-drivers in one class.
 *
 * Synchronous, like the module's other reactors; Task R2 owns the queueing
 * conformance pass.
 */
class NewOwnerNotificationReactor extends Reactor
{
    public function onOwnershipTransferExecuted(OwnershipTransferExecuted $event): void
    {
        $transfer = OwnershipTransferRequest::query()->findOrFail($event->requestId);
        $incomingOwner = User::query()->findOrFail($event->newOwnerUserId);

        $incomingOwner->notify(new OwnershipReceivedNotification($transfer));
    }
}
