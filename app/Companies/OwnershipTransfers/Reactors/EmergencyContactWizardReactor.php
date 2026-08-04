<?php

namespace App\Companies\OwnershipTransfers\Reactors;

use App\Models\User;
use App\Companies\Entities\CompanyUser;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExecuted;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\IO\Notifications\EmergencyContactRequiredNotification;

/**
 * SM-1-05 T4 — prompts the INCOMING owner to set an emergency contact
 * (UC-1-093 alt-flow 5a → UC-1-029).
 *
 * §S10b makes the contact mandatory for owners, and T1 already refuses a target
 * who lacks one. This is not redundant with that guard: a transfer can sit
 * verified for days, the contact can be cleared in the meantime, and it is the
 * moment the role actually lands that the obligation attaches.
 *
 * SHORT-CIRCUITS when the new owner already has one (SD-1-02 OQ #3) — the
 * common case, since T1 required it. Prompting anyway would train owners to
 * ignore the notice.
 *
 * Reads the pivot rather than the event payload, and that is safe here where
 * amendment N would normally bite: `MembershipProjector` ($weight 20) writes the
 * incoming owner's pivot row before any reactor runs, and the emergency-contact
 * columns it carries are untouched by T4 — this reactor reads a field the
 * transaction never wrote. Synchronous, like the module's other reactors; Task
 * R2 owns the queueing conformance pass.
 */
class EmergencyContactWizardReactor extends Reactor
{
    public function onOwnershipTransferExecuted(OwnershipTransferExecuted $event): void
    {
        $membership = CompanyUser::query()->findOrFail($event->newOwnerMembershipId);

        if ($membership->emergency_contact_name !== null) {
            return;
        }

        $transfer = OwnershipTransferRequest::query()->findOrFail($event->requestId);
        $newOwner = User::query()->findOrFail($event->newOwnerUserId);

        $newOwner->notify(new EmergencyContactRequiredNotification($transfer));
    }
}
