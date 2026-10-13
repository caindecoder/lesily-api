<?php

namespace App\Compliance\Dsar\Projectors;

use App\Compliance\Dsar\Events\DsarSlaBreached;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarDownloadExpired;
use App\Compliance\Dsar\Events\DsarRequestRejected;
use App\Compliance\Dsar\Events\DsarRequestCancelled;
use App\Compliance\Dsar\Events\DsarRequestFulfilled;
use App\Compliance\Dsar\Events\DsarRequestSubmitted;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class DsarRequestProjector extends Projector
{
    public function onDsarRequestSubmitted(DsarRequestSubmitted $event): void
    {
        DsarRequestRecord::query()->create([
            'id' => $event->requestId,
            'company_id' => $event->companyId,
            'subject_user_id' => $event->subjectUserId,
            'requested_by' => $event->requestedByUserId,
            'requested_by_role' => $event->requestedByRole,
            'request_type' => $event->requestType,
            'status' => DsarRequestStatus::InProgress,
            'requested_at' => $event->requestedAt,
            'sla_deadline' => $event->slaDeadline,
            'created_by' => $event->requestedByUserId,
        ]);
    }

    public function onDsarRequestFulfilled(DsarRequestFulfilled $event): void
    {
        DsarRequestRecord::query()->findOrFail($event->aggregateRootUuid())->update([
            'status' => DsarRequestStatus::Fulfilled,
            'completed_at' => $event->completedAt,
            'file_path' => $event->filePath,
            'download_expires_at' => $event->downloadExpiresAt,
        ]);
    }

    public function onDsarRequestRejected(DsarRequestRejected $event): void
    {
        DsarRequestRecord::query()->findOrFail($event->aggregateRootUuid())->update([
            'status' => DsarRequestStatus::Rejected,
            'rejected_at' => $event->rejectedAt,
            'rejection_reason' => $event->reason,
        ]);
    }

    public function onDsarRequestCancelled(DsarRequestCancelled $event): void
    {
        DsarRequestRecord::query()->findOrFail($event->aggregateRootUuid())->update([
            'status' => DsarRequestStatus::Cancelled,
            'cancelled_at' => $event->cancelledAt,
            'cancelled_by' => $event->cancelledByUserId,
        ]);
    }

    public function onDsarSlaBreached(DsarSlaBreached $event): void
    {
        DsarRequestRecord::query()->findOrFail($event->aggregateRootUuid())->update([
            'sla_breached_at' => $event->breachedAt,
        ]);
    }

    public function onDsarDownloadExpired(DsarDownloadExpired $event): void
    {
        DsarRequestRecord::query()->findOrFail($event->aggregateRootUuid())->update([
            'download_expired_at' => $event->expiredAt,
        ]);
    }
}
