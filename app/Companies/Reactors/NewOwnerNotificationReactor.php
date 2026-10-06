<?php

namespace App\Companies\Reactors;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Companies\Events\CompanyOwnershipTransferred;
use App\Companies\Entities\Company as CompanyReadModel;
use App\Foundation\Notifications\Enums\NotificationType;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Foundation\Notifications\Notifications\SystemNotification;

/**
 * Tells the incoming Owner they now hold the company (UC-1-028 / spec D4).
 * The NEW owner only — the outgoing owner performed the action (routine) or is
 * unreachable (admin case); their silence is asserted as behavior. Queued
 * after-commit so a delivery failure lands in failed_jobs and can never roll
 * back a committed transfer. Auto-discovered.
 */
class NewOwnerNotificationReactor extends Reactor implements ShouldQueue
{
    public function onCompanyOwnershipTransferred(CompanyOwnershipTransferred $event): void
    {
        $company = CompanyReadModel::query()->findOrFail($event->aggregateRootUuid());

        User::query()->findOrFail($event->newOwnerUserId)
            ->notify(new SystemNotification(NotificationType::OwnershipReceived, $company->name, $company->id));
    }
}
