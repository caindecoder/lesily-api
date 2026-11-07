<?php

namespace App\Compliance\Breach\Reactors;

use App\Models\User;
use RuntimeException;
use App\Companies\Entities\Company;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use App\Compliance\Breach\Events\BreachDeadlineMissed;
use App\Compliance\Breach\Events\BreachCompanyNotified;
use App\Compliance\Breach\Events\BreachFilingRequested;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Foundation\Notifications\Enums\NotificationType;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Compliance\Breach\Events\BreachFiledWithRegulator;
use App\Foundation\Notifications\Notifications\SystemNotification;
use App\Foundation\RolesPermissions\Services\PlatformAdminAllowlist;

class BreachNotificationReactor extends Reactor implements ShouldQueue
{
    public function __construct(protected readonly PlatformAdminAllowlist $platformAdmins) {}

    public function onBreachFilingRequested(BreachFilingRequested $event): void
    {
        $company = Company::query()->findOrFail($event->companyId);
        $admins = User::query()->whereKey($this->platformAdmins->userIds())->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new SystemNotification(NotificationType::BreachFilingRequested, $company->name, null, [
                'incident' => $event->breachId,
            ]));
        } else {
            report(new RuntimeException("No platform admin to alert about the filing request of company {$company->getKey()} on breach incident {$event->breachId}."));
        }
    }

    public function onBreachDeadlineMissed(BreachDeadlineMissed $event): void
    {
        $incident = BreachIncidentRecord::query()->findOrFail($event->breachId);
        $admins = User::query()->whereKey($this->platformAdmins->userIds())->get();

        if ($admins->isEmpty()) {
            report(new RuntimeException("No platform admin to alert about the missed deadline of breach incident {$event->breachId}."));

            return;
        }

        Notification::send($admins, new SystemNotification(NotificationType::BreachDeadlineMissed, $this->companyNamesOf($incident), null, [
            'incident' => $event->breachId,
            'deadline' => $incident->deadline->format('j M Y, H:i').' UTC',
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

    public function onBreachFiledWithRegulator(BreachFiledWithRegulator $event): void
    {
        $company = Company::query()->findOrFail($event->companyId);

        Notification::send($company->ownerAndAdmins(), new SystemNotification(NotificationType::BreachFiledWithRegulator, $company->name, $company->getKey(), [
            'incident' => $event->breachId,
            'filed_at' => $event->filedAt->format('j M Y, H:i').' UTC',
        ]));
    }

    protected function companyNamesOf(BreachIncidentRecord $incident): string
    {
        return Company::query()
            ->whereIn('id', $incident->companies()->select('company_id'))
            ->orderBy('name')
            ->pluck('name')
            ->implode(', ');
    }
}
