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
    public function __construct(protected readonly PlatformAdminAllowlist $platformAdminAllowlist) {}

    public function onBreachFilingRequested(BreachFilingRequested $breachFilingRequested): void
    {
        $company = Company::query()->findOrFail($breachFilingRequested->companyId);
        $platformAdmins = User::query()->whereKey($this->platformAdminAllowlist->userIds())->get();

        if ($platformAdmins->isNotEmpty()) {
            Notification::send($platformAdmins, new SystemNotification(NotificationType::BreachFilingRequested, $company->name, null, [
                'incident' => $breachFilingRequested->breachId,
            ]));
        } else {
            report(new RuntimeException("No platform admin to alert about the filing request of company {$company->getKey()} on breach incident {$breachFilingRequested->breachId}."));
        }
    }

    public function onBreachDeadlineMissed(BreachDeadlineMissed $breachDeadlineMissed): void
    {
        $breachIncident = BreachIncidentRecord::query()->findOrFail($breachDeadlineMissed->breachId);
        $platformAdmins = User::query()->whereKey($this->platformAdminAllowlist->userIds())->get();

        if ($platformAdmins->isNotEmpty()) {
            Notification::send($platformAdmins, new SystemNotification(NotificationType::BreachDeadlineMissed, $this->companyNamesOf($breachIncident), null, [
                'incident' => $breachDeadlineMissed->breachId,
                'deadline' => $breachIncident->deadline->format('j M Y, H:i').' UTC',
            ]));
        } else {
            report(new RuntimeException("No platform admin to alert about the missed deadline of breach incident {$breachDeadlineMissed->breachId}."));
        }
    }

    public function onBreachCompanyNotified(BreachCompanyNotified $breachCompanyNotified): void
    {
        $company = Company::query()->findOrFail($breachCompanyNotified->companyId);
        $recipients = $company->ownerAndAdmins();

        if ($recipients->isEmpty()) {
            report(new RuntimeException("No owner or admin of company {$company->getKey()} to tell about breach incident {$breachCompanyNotified->breachId}."));
        }

        Notification::send(
            $recipients,
            new SystemNotification(NotificationType::BreachCompanyNotice, $company->name, $company->getKey(), $breachCompanyNotified->part),
        );
    }

    public function onBreachFiledWithRegulator(BreachFiledWithRegulator $breachFiledWithRegulator): void
    {
        $company = Company::query()->findOrFail($breachFiledWithRegulator->companyId);

        Notification::send($company->ownerAndAdmins(), new SystemNotification(NotificationType::BreachFiledWithRegulator, $company->name, $company->getKey(), [
            'incident' => $breachFiledWithRegulator->breachId,
            'filed_at' => $breachFiledWithRegulator->filedAt->format('j M Y, H:i').' UTC',
        ]));
    }

    protected function companyNamesOf(BreachIncidentRecord $breachIncident): string
    {
        return Company::query()
            ->whereIn('id', $breachIncident->companies()->select('company_id'))
            ->orderBy('name')
            ->pluck('name')
            ->implode(', ');
    }
}
