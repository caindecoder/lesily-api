<?php

namespace App\Companies\OwnershipTransfers\Reactors;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Companies\OwnershipTransfers\Enums\TransitionSource;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExpired;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferVerificationLapsedNotification;

/**
 * Tells the VERIFYING ADMIN that the transfer they cleared expired unexecuted
 * (SM-1-05 T9 / UC-1-117) — the dual-notification asymmetry that separates T9
 * from T8.
 *
 * Filtered to the verified stage, and that filter is the reason this class
 * exists separately from `RequesterNotificationReactor`: a requested-stage
 * expiry has no verifier at all, so `verified_by` would be null and there would
 * be nobody to notify. Keeping the filter here rather than as a branch in the
 * requester's reactor means each reactor answers one question.
 *
 * The filter is the exact construct that went unenforced at s155 (a
 * source-filtered reactor left 91 tests green while the ruling it implemented
 * sat asserted in four docblocks), so it is pinned from BOTH sides: the T9 row
 * proves the notice fires, and a T8 row proves it does not.
 *
 * `verified_by` is read from the PROJECTION (ruling M3), not from the T2 stored
 * event UC-1-117 names. The column is written once at T2 by the table's sole
 * writer straight from that event's own payload field and rebuilt from it on
 * replay, so it cannot drift without the projector being broken — whereas an
 * event-store read would be the first in the app and would couple this reactor
 * to an event class name and payload shape (OQ-1-108).
 *
 * Reading the row here is safe despite amendment N: T9 is a single-aggregate
 * write and this event's own projector runs before its reactors, so the row
 * is committed by the time we look. Queued (ShouldQueue, Task R2 — CD
 * `Queued.` conformance); delivery-time staleness cannot arise on either
 * read: the event lands the row in the terminal `expired` state, and
 * `verified_by` is written exactly once at T2 (the M3 argument) — neither
 * moves between commit and delivery.
 */
class VerifierNotificationReactor extends Reactor implements ShouldQueue
{
    public function onOwnershipTransferExpired(OwnershipTransferExpired $event): void
    {
        if ($event->source !== TransitionSource::VerifiedStage->value) {
            return;
        }

        $transfer = OwnershipTransferRequest::query()->findOrFail($event->requestId);
        $verifier = User::query()->findOrFail($transfer->verified_by);

        $verifier->notify(new OwnershipTransferVerificationLapsedNotification($transfer));
    }
}
