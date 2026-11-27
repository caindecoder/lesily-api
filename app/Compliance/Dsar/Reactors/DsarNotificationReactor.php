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
    public function __construct(private readonly PlatformAdminAllowlist $platformAdminAllowlist) {}

    public function onDsarRequestFulfilled(DsarRequestFulfilled $dsarRequestFulfilled): void
    {
        $this->notifyAbout($dsarRequestFulfilled, NotificationType::DsarExportReady);
    }

    public function onDsarRequestRejected(DsarRequestRejected $dsarRequestRejected): void
    {
        $this->notifyAbout($dsarRequestRejected, NotificationType::DsarExportFailed);
    }

    public function onDsarRequestCancelled(DsarRequestCancelled $dsarRequestCancelled): void
    {
        $this->notifyAbout($dsarRequestCancelled, NotificationType::DsarRequestWithdrawn);
    }

    /**
     * One critical alert to each platform admin naming the request, its
     * company and its deadline — a platform notice, filed under no company.
     * With no admin to alert, the gap is reported; the breach stays recorded.
     */
    public function onDsarSlaBreached(DsarSlaBreached $dsarSlaBreached): void
    {
        $dsarRequest = DsarRequestRecord::query()->findOrFail($dsarSlaBreached->aggregateRootUuid());
        $platformAdmins = User::query()->whereKey($this->platformAdminAllowlist->userIds())->get();

        if ($platformAdmins->isNotEmpty()) {
            Notification::send($platformAdmins, $this->deadlineMissedAlert($dsarRequest));
        } else {
            report(new RuntimeException("No platform admin to alert about DSAR request {$dsarRequest->getKey()}."));
        }
    }

    protected function deadlineMissedAlert(DsarRequestRecord $dsarRequest): SystemNotification
    {
        $company = Company::query()->findOrFail($dsarRequest->company_id);

        return new SystemNotification(NotificationType::DsarDeadlineMissed, $company->name, null, [
            'request' => $dsarRequest->getKey(),
            'deadline' => $dsarRequest->sla_deadline->format('j M Y, H:i').' UTC',
        ]);
    }

    /**
     * One notice each to the request's subject and to whoever asked for it —
     * one in all when they are the same person.
     */
    protected function notifyAbout(ShouldBeStored $shouldBeStored, NotificationType $notificationType): void
    {
        $dsarRequest = DsarRequestRecord::query()->findOrFail($shouldBeStored->aggregateRootUuid());
        $company = Company::query()->findOrFail($dsarRequest->company_id);

        Notification::send(
            collect([$dsarRequest->subject, $dsarRequest->requestedBy])->unique(fn (User $user): string => $user->getKey()),
            new SystemNotification($notificationType, $company->name, $company->getKey()),
        );
    }
}
