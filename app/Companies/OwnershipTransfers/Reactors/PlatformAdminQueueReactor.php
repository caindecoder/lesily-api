<?php

namespace App\Companies\OwnershipTransfers\Reactors;

use App\Models\User;
use App\Foundation\Notifications\Enums\NotificationType;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Foundation\Notifications\Notifications\SystemNotification;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferIdentityVerified;

/**
 * Tells the platform-admin queue that a verified transfer is now waiting on
 * execution (SM-1-05 T2 → the UC-1-093 reviewers' signal). Deliberately NOT a
 * requester notification: T2 is a platform-side step, and the owner learns of
 * it by reading UC-1-125, not by being pushed at.
 *
 * Reading the transfer row here is safe despite amendment N: T2 is a
 * single-aggregate write and this event's own projector runs before its
 * reactors, so the row is committed and current by the time we look.
 *
 * Recipients resolve from the interim `platform.admin_user_ids` allowlist (the
 * SupportQueueNotificationReactor idiom), and the notification is the native
 * SystemNotification per OQ-1-104.
 */
class PlatformAdminQueueReactor extends Reactor
{
    public function onOwnershipTransferIdentityVerified(OwnershipTransferIdentityVerified $event): void
    {
        $transfer = OwnershipTransferRequest::query()->with('company')->findOrFail($event->requestId);

        $adminIds = collect((array) config('platform.admin_user_ids', []))
            ->map(fn ($id): string => trim((string) $id))
            ->reject(fn (string $id): bool => $id === '');

        User::query()
            ->whereIn('id', $adminIds)
            ->get()
            ->each(fn (User $admin) => $admin->notify(
                new SystemNotification(NotificationType::GeneralAccountNotice, $transfer->company?->name),
            ));
    }
}
