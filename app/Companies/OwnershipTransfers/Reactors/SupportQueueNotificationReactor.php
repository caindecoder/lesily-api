<?php

namespace App\Companies\OwnershipTransfers\Reactors;

use App\Models\User;
use App\Foundation\Http\Values\PlatformAdminAllowlist;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRequested;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferRequestedNotification;

/**
 * Alerts the platform support team that a transfer request entered the
 * verification queue (SM-1-05 T1). Recipients resolve from the interim
 * `platform.admin_user_ids` allowlist (s136 binding — the platform-admin
 * slice replaces it with real admin auth); the notification is
 * OwnershipTransferRequestedNotification, not cd-notifications' bespoke
 * dispatcher (CD-1-05's Slack/Teams note is unbuilt infra).
 *
 * It said "the native SystemNotification per OQ-1-104" until s152. The H3
 * retrofit replaced the body and left the docblock asserting the opposite —
 * the same way QueuedMailChannel's docblock went stale, which is the failure
 * that earned the SendsQueuedMail extraction. Two siblings, both missed, in
 * the commit that made the statement false.
 */
class SupportQueueNotificationReactor extends Reactor
{
    public function onOwnershipTransferRequested(OwnershipTransferRequested $event): void
    {
        $transfer = OwnershipTransferRequest::query()->findOrFail($event->requestId);

        User::query()
            ->whereIn('id', PlatformAdminAllowlist::userIds())
            ->get()
            ->each(fn (User $admin) => $admin->notify(
                new OwnershipTransferRequestedNotification($transfer),
            ));
    }
}
