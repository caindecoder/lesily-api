<?php

namespace App\Companies\OwnershipTransfers\Reactors;

use App\Models\User;
use App\Companies\Entities\CompanyUser;
use Illuminate\Contracts\Queue\ShouldQueue;
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
 * Reads the pivot rather than the event payload — deliberately, and the
 * queued posture (ShouldQueue, Task R2 — CD `Queued.` conformance) sharpens
 * why: the short-circuit now evaluates at DELIVERY time, so an owner who set
 * their contact between commit and delivery is correctly not prompted. The
 * obligation is about the contact's presence when the prompt would land, not
 * at the execution instant.
 *
 * Before R2 this class's bare findOrFail was an ACCIDENTAL transaction guard
 * (s161 Phase-5 finding): running inside the interactor's transaction, its
 * SoftDeletes-scoped miss on a concurrently-removed pivot threw and rolled
 * the whole transfer back. Queueing removes that accident BY DESIGN — the
 * invariant is held where it belongs, by the interactor's in-transaction
 * `lockForUpdate()` reads (ruling P16: `lockedCompany()` + `liveMembership()`
 * on both pivots), verified at the R2 pre-flight. A pivot row that vanishes
 * AFTER the locks released stays loud here: findOrFail in a worker fails the
 * job into failed_jobs rather than silently skipping the §S10b obligation.
 */
class EmergencyContactWizardReactor extends Reactor implements ShouldQueue
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
