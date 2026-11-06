<?php

namespace App\Compliance\Breach\Reactors;

use App\Models\User;
use RuntimeException;
use App\Companies\Entities\Company;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use App\Compliance\Breach\Events\BreachCompanyNotified;
use App\Compliance\Breach\Events\BreachFilingRequested;
use App\Foundation\Notifications\Enums\NotificationType;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Foundation\Notifications\Notifications\SystemNotification;
use App\Foundation\RolesPermissions\Services\PlatformAdminAllowlist;

class BreachNotificationReactor extends Reactor implements ShouldQueue
{
    public function __construct(protected readonly PlatformAdminAllowlist $platformAdmins) {}

    public function onBreachFilingRequested(BreachFilingRequested $event): void
    {
        $company = Company::query()->findOrFail($event->companyId);
        $admins = User::query()->whereKey($this->platformAdmins->userIds())->get();

        Notification::send($admins, new SystemNotification(NotificationType::BreachFilingRequested, $company->name, null, [
            'incident' => $event->breachId,
        ]));
    }

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
