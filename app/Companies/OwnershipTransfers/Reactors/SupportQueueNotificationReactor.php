<?php

namespace App\Companies\OwnershipTransfers\Reactors;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Foundation\Notifications\Enums\NotificationType;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Foundation\Notifications\Notifications\SystemNotification;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRequested;

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
        $company = Company::query()->findOrFail($event->companyId);

        // Same normalization idiom as EnsurePlatformAdmin (the allowlist's one
        // other consumer): cast, trim, reject blanks — padding fails closed.
        $adminIds = collect((array) config('platform.admin_user_ids', []))
            ->map(fn ($id): string => trim((string) $id))
            ->reject(fn (string $id): bool => $id === '');

        User::query()
            ->whereIn('id', $adminIds)
            ->get()
            ->each(fn (User $admin) => $admin->notify(
                new SystemNotification(NotificationType::GeneralAccountNotice, $company->name),
            ));
    }
}
