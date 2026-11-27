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
    public function onDsarRequestSubmitted(DsarRequestSubmitted $dsarRequestSubmitted): void
    {
        DsarRequestRecord::query()->create([
            'id' => $dsarRequestSubmitted->requestId,
            'company_id' => $dsarRequestSubmitted->companyId,
            'subject_user_id' => $dsarRequestSubmitted->subjectUserId,
            'requested_by' => $dsarRequestSubmitted->requestedByUserId,
            'requested_by_role' => $dsarRequestSubmitted->requestedByRole,
            'request_type' => $dsarRequestSubmitted->requestType,
            'status' => DsarRequestStatus::InProgress,
            'requested_at' => $dsarRequestSubmitted->requestedAt,
            'sla_deadline' => $dsarRequestSubmitted->slaDeadline,
            'created_by' => $dsarRequestSubmitted->requestedByUserId,
        ]);
    }

    public function onDsarRequestFulfilled(DsarRequestFulfilled $dsarRequestFulfilled): void
    {
        DsarRequestRecord::query()->findOrFail($dsarRequestFulfilled->aggregateRootUuid())->update([
            'status' => DsarRequestStatus::Fulfilled,
            'completed_at' => $dsarRequestFulfilled->completedAt,
            'file_path' => $dsarRequestFulfilled->filePath,
            'download_expires_at' => $dsarRequestFulfilled->downloadExpiresAt,
        ]);
    }

    public function onDsarRequestRejected(DsarRequestRejected $dsarRequestRejected): void
    {
        DsarRequestRecord::query()->findOrFail($dsarRequestRejected->aggregateRootUuid())->update([
            'status' => DsarRequestStatus::Rejected,
            'rejected_at' => $dsarRequestRejected->rejectedAt,
            'rejection_reason' => $dsarRequestRejected->reason,
        ]);
    }

    public function onDsarRequestCancelled(DsarRequestCancelled $dsarRequestCancelled): void
    {
        DsarRequestRecord::query()->findOrFail($dsarRequestCancelled->aggregateRootUuid())->update([
            'status' => DsarRequestStatus::Cancelled,
            'cancelled_at' => $dsarRequestCancelled->cancelledAt,
            'cancelled_by' => $dsarRequestCancelled->cancelledByUserId,
        ]);
    }

    public function onDsarSlaBreached(DsarSlaBreached $dsarSlaBreached): void
    {
        DsarRequestRecord::query()->findOrFail($dsarSlaBreached->aggregateRootUuid())->update([
            'sla_breached_at' => $dsarSlaBreached->breachedAt,
        ]);
    }

    public function onDsarDownloadExpired(DsarDownloadExpired $dsarDownloadExpired): void
    {
        DsarRequestRecord::query()->findOrFail($dsarDownloadExpired->aggregateRootUuid())->update([
            'download_expired_at' => $dsarDownloadExpired->expiredAt,
        ]);
    }
}
