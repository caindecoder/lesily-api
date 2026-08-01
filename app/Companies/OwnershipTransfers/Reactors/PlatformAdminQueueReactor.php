<?php

namespace App\Companies\OwnershipTransfers\Reactors;

use App\Models\User;
use RuntimeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Foundation\Http\Values\PlatformAdminAllowlist;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferIdentityVerified;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferVerifiedNotification;

/**
 * Tells the platform-admin queue that a verified transfer is now waiting on
 * execution (SM-1-05 T2 → the UC-1-093 reviewers' signal). Deliberately NOT a
 * requester notification: T2 is a platform-side step, and the owner learns of
 * it by reading UC-1-125, not by being pushed at.
 *
 * Reading the transfer row here is safe despite amendment N: T2 is a
 * single-aggregate write and this event's own projector runs before its
 * reactors, so the row is committed by the time we look. "Current" is no
 * longer free now the class is Queued (Task R2, CD conformance): the job may
 * deliver after the row moved on, so the guard below (ruling Q2, s164)
 * re-reads at delivery and SKIPS once status has left `verified` — an alert
 * asserting a live claim ("awaiting execution") must not describe a transfer
 * that is already terminal. A VANISHED row stays loud, deliberately:
 * findOrFail in a worker fails the job into failed_jobs rather than
 * swallowing a state no built flow can produce.
 *
 * Recipients resolve from the interim `platform.admin_user_ids` allowlist (the
 * SupportQueueNotificationReactor idiom), and the notification is
 * OwnershipTransferVerifiedNotification — corrected s152; the H3 retrofit
 * swapped the body and left this line still naming SystemNotification.
 */
class PlatformAdminQueueReactor extends Reactor implements ShouldQueue
{
    public function onOwnershipTransferIdentityVerified(OwnershipTransferIdentityVerified $event): void
    {
        $transfer = OwnershipTransferRequest::query()->findOrFail($event->requestId);

        if ($transfer->status !== OwnershipTransferStatus::Verified) {
            report(new RuntimeException(
                "Stale platform-admin alert skipped: transfer {$transfer->getKey()} left verified before delivery (now {$transfer->status->value}).",
            ));

            return;
        }

        User::query()
            ->whereIn('id', PlatformAdminAllowlist::userIds())
            ->get()
            ->each(fn (User $admin) => $admin->notify(
                new OwnershipTransferVerifiedNotification($transfer),
            ));
    }
}
