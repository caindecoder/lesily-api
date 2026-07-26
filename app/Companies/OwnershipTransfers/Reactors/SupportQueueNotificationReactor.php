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
 * slice replaces it with real admin auth); notification is the native
 * SystemNotification per OQ-1-104, not cd-notifications' bespoke dispatcher
 * (CD-1-05's Slack/Teams note is unbuilt infra).
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
