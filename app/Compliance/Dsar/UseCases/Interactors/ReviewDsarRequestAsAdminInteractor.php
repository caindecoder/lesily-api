<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ReviewDsarRequestAsAdminInteractor
{
    /**
     * The read and its audit row share one transaction — no read without its evidence.
     */
    public function execute(string $requestId, User $platformAdmin, RequestOrigin $requestOrigin): DsarRequestRecord
    {
        return DB::transaction(function () use ($requestId, $platformAdmin, $requestOrigin): DsarRequestRecord {
            $dsarRequest = DsarRequestRecord::query()->whereKey($requestId)->first()
                ?? throw new ModelNotFoundException;

            $this->recordAdminRead($dsarRequest, $platformAdmin, $requestOrigin);

            return $dsarRequest;
        });
    }

    protected function recordAdminRead(DsarRequestRecord $dsarRequest, User $platformAdmin, RequestOrigin $requestOrigin): void
    {
        AuditEntry::query()->create([
            'company_id' => $dsarRequest->company_id,
            'stored_event_id' => null,
            'event_class' => 'DsarRequestViewedByAdmin',
            'subject_type' => AuditSubjectType::DsarRequest,
            'subject_id' => $dsarRequest->getKey(),
            'subject_label' => null,
            'action' => AuditAction::DsarAdminViewed,
            'description' => 'DSAR request viewed by a platform admin.',
            'actor_user_id' => $platformAdmin->getKey(),
            'actor_role_code' => AuditEntry::STAFF_ROLE_CODE,
            'actor_label' => $platformAdmin->name,
            'ip_address' => $requestOrigin->ip,
            'user_agent' => $requestOrigin->boundedUserAgent(),
            'request_id' => $requestOrigin->boundedRequestId(),
            'metadata' => null,
            'recorded_at' => now(),
        ]);
    }
}
