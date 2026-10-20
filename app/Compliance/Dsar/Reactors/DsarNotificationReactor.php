<?php

namespace App\Compliance\Dsar\Reactors;

use App\Models\User;
use RuntimeException;
use App\Companies\Entities\Company;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use App\Compliance\Dsar\Events\DsarSlaBreached;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarRequestRejected;
use App\Compliance\Dsar\Events\DsarRequestCancelled;
use App\Compliance\Dsar\Events\DsarRequestFulfilled;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;
use App\Foundation\Notifications\Enums\NotificationType;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Foundation\Notifications\Notifications\SystemNotification;
use App\Foundation\RolesPermissions\Services\PlatformAdminAllowlist;

class DsarNotificationReactor extends Reactor implements ShouldQueue
{
    public function __construct(private readonly PlatformAdminAllowlist $platformAdmins) {}

    public function onDsarRequestFulfilled(DsarRequestFulfilled $event): void
    {
        $this->notifyAbout($event, NotificationType::DsarExportReady);
    }

    public function onDsarRequestRejected(DsarRequestRejected $event): void
    {
        $this->notifyAbout($event, NotificationType::DsarExportFailed);
    }

    public function onDsarRequestCancelled(DsarRequestCancelled $event): void
    {
        $this->notifyAbout($event, NotificationType::DsarRequestWithdrawn);
    }

    /**
     * One critical alert to each platform admin naming the request, its
     * company and its deadline — a platform notice, filed under no company.
     * With no admin to alert, the gap is reported; the breach stays recorded.
     */
    public function onDsarSlaBreached(DsarSlaBreached $event): void
    {
        $request = DsarRequestRecord::query()->findOrFail($event->aggregateRootUuid());
        $admins = User::query()->whereKey($this->platformAdmins->userIds())->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, $this->deadlineMissedAlert($request));
        } else {
            report(new RuntimeException("No platform admin to alert about DSAR request {$request->getKey()}."));
        }
    }

    protected function deadlineMissedAlert(DsarRequestRecord $request): SystemNotification
    {
        $company = Company::query()->findOrFail($request->company_id);

        return new SystemNotification(NotificationType::DsarDeadlineMissed, $company->name, null, [
            'request' => $request->getKey(),
            'deadline' => $request->sla_deadline->format('j M Y, H:i').' UTC',
        ]);
    }

    /**
     * One notice each to the request's subject and to whoever asked for it —
     * one in all when they are the same person.
     */
    protected function notifyAbout(ShouldBeStored $event, NotificationType $type): void
    {
        $request = DsarRequestRecord::query()->findOrFail($event->aggregateRootUuid());
        $company = Company::query()->findOrFail($request->company_id);

        Notification::send(
            collect([$request->subject, $request->requestedBy])->unique(fn (User $user): string => $user->getKey()),
            new SystemNotification($type, $company->name, $company->getKey()),
        );
    }
}
