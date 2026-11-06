<?php

namespace App\Compliance\Breach\Reactors;

use RuntimeException;
use App\Companies\Entities\Company;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use App\Compliance\Breach\Events\BreachCompanyNotified;
use App\Foundation\Notifications\Enums\NotificationType;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Foundation\Notifications\Notifications\SystemNotification;

class BreachNotificationReactor extends Reactor implements ShouldQueue
{
    public function onBreachCompanyNotified(BreachCompanyNotified $event): void
    {
        $company = Company::query()->findOrFail($event->companyId);
        $recipients = $company->ownerAndAdmins();

        if ($recipients->isEmpty()) {
            report(new RuntimeException("No owner or admin of company {$company->getKey()} to tell about breach incident {$event->breachId}."));
        }

        Notification::send(
            $recipients,
            new SystemNotification(NotificationType::BreachCompanyNotice, $company->name, $company->getKey(), $event->part),
        );
    }
}
