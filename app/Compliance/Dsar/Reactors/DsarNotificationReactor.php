<?php

namespace App\Compliance\Dsar\Reactors;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarRequestRejected;
use App\Compliance\Dsar\Events\DsarRequestCancelled;
use App\Compliance\Dsar\Events\DsarRequestFulfilled;
use App\Foundation\Notifications\Enums\NotificationType;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Foundation\Notifications\Notifications\SystemNotification;

class DsarNotificationReactor extends Reactor implements ShouldQueue
{
    public function onDsarRequestFulfilled(DsarRequestFulfilled $event): void
    {
        $this->notifyAbout($event->aggregateRootUuid(), NotificationType::DsarExportReady);
    }

    public function onDsarRequestRejected(DsarRequestRejected $event): void
    {
        $this->notifyAbout($event->aggregateRootUuid(), NotificationType::DsarExportFailed);
    }

    public function onDsarRequestCancelled(DsarRequestCancelled $event): void
    {
        $this->notifyAbout($event->aggregateRootUuid(), NotificationType::DsarRequestWithdrawn);
    }

    /**
     * One notice each to the request's subject and to whoever asked for it —
     * one in all when they are the same person.
     */
    private function notifyAbout(string $requestId, NotificationType $type): void
    {
        $request = DsarRequestRecord::query()->with(['subject', 'requestedBy'])->findOrFail($requestId);
        $company = Company::query()->findOrFail($request->company_id);

        Notification::send(
            collect([$request->subject, $request->requestedBy])->unique(fn (User $user): string => $user->getKey()),
            new SystemNotification($type, $company->name, $company->getKey()),
        );
    }
}
