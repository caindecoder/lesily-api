<?php

namespace App\Companies\OwnershipTransfers\Reactors;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExpired;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRejected;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferExpiredNotification;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferRejectedNotification;

/**
 * Tells the ORIGINAL REQUESTER that their transfer ended, and why (SM-1-05 §7 —
 * RequesterNotificationReactor listens to Rejected and Expired; both expiry
 * windows join it unfiltered).
 *
 * T7 deliberately does NOT join it, and never was going to: on a withdrawal the
 * requester IS the actor, so telling them is noise, and SM-1-05 §7's Cancelled
 * row lists only SupportQueueNotificationReactor + AuditTrailReactor. This line
 * previously read "T7/T9 join it at their own tasks", which would have sent the
 * next reader looking for a notification the design forbids.
 *
 * Only the requester is notified. The designated new owner is deliberately not:
 * a rejection lands before any role change, so they were never affected, and
 * telling them a transfer they may not have known about was declined leaks a
 * decision that was never theirs (UC-1-128 stakeholders).
 *
 * Reading the transfer row here is safe despite amendment N: T3/T5 is a
 * single-aggregate write and this event's own projector runs before its
 * reactors, so the row is committed by the time we look. Queued (ShouldQueue,
 * Task R2 — CD `Queued.` conformance), and delivery-time staleness is benign
 * here BY the states involved: both handled events land the row in a TERMINAL
 * state (rejected / expired), which no built flow moves again, and
 * `current_owner_user_id` records the requester at T1 — the projector never
 * rewrites it after the terminal transition. Contrast
 * PlatformAdminQueueReactor, whose alert asserts a live claim and carries the
 * Q2 delivery-time guard.
 */
class RequesterNotificationReactor extends Reactor implements ShouldQueue
{
    public function onOwnershipTransferRejected(OwnershipTransferRejected $event): void
    {
        $transfer = OwnershipTransferRequest::query()->findOrFail($event->requestId);
        $requester = User::query()->findOrFail($transfer->current_owner_user_id);

        $requester->notify(new OwnershipTransferRejectedNotification(
            $transfer,
            $event->rejectionReason,
        ));
    }

    /**
     * SM-1-05 T8 + T9. Unfiltered by `source`, deliberately: both windows end the
     * requester's transfer without it completing, so both owe them the notice.
     * The verifier's separate T9-only notice is what carries the asymmetry
     * (UC-1-117), and it lives in its own reactor rather than as a branch here.
     */
    public function onOwnershipTransferExpired(OwnershipTransferExpired $event): void
    {
        $transfer = OwnershipTransferRequest::query()->findOrFail($event->requestId);
        $requester = User::query()->findOrFail($transfer->current_owner_user_id);

        $requester->notify(new OwnershipTransferExpiredNotification($transfer));
    }
}
