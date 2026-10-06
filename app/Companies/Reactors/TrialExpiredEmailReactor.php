<?php

namespace App\Companies\Reactors;

use Illuminate\Contracts\Queue\ShouldQueue;
use App\Companies\Events\CompanyTrialExpired;
use App\Companies\Entities\Company as CompanyReadModel;
use App\Foundation\Notifications\Enums\NotificationType;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Foundation\Notifications\Notifications\SystemNotification;

/**
 * Emails the company owner when their 30-day trial lapses (UC-1-049 Sc1). The read-only
 * banner the other members see is a frontend concern; this reactor is the backend side
 * effect only. Reuses the foundation SystemNotification (UC-1-087) per the native-
 * notification pattern (OQ-1-104), not cd-notifications' bespoke dispatcher. A company with
 * no owner (e.g. arranged purely by event replay) is a no-op.
 */
class TrialExpiredEmailReactor extends Reactor implements ShouldQueue
{
    public function onCompanyTrialExpired(CompanyTrialExpired $event): void
    {
        $company = CompanyReadModel::query()->findOrFail($event->aggregateRootUuid());

        $company->owner()?->notify(new SystemNotification(NotificationType::GeneralAccountNotice, $company->name, $company->id));
    }
}
