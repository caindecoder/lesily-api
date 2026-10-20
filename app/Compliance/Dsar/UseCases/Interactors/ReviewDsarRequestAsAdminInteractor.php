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
    public function execute(string $requestId, User $admin, RequestOrigin $context): DsarRequestRecord
    {
        return DB::transaction(function () use ($requestId, $admin, $context): DsarRequestRecord {
            $request = DsarRequestRecord::query()->whereKey($requestId)->first()
                ?? throw new ModelNotFoundException;

            $this->recordAdminRead($request, $admin, $context);

            return $request;
        });
    }

    protected function recordAdminRead(DsarRequestRecord $request, User $admin, RequestOrigin $context): void
    {
        AuditEntry::query()->create([
            'company_id' => $request->company_id,
            'stored_event_id' => null,
            'event_class' => 'DsarRequestViewedByAdmin',
            'subject_type' => AuditSubjectType::DsarRequest,
            'subject_id' => $request->getKey(),
            'subject_label' => null,
            'action' => AuditAction::DsarAdminViewed,
            'description' => 'DSAR request viewed by a platform admin.',
            'actor_user_id' => $admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $admin->name,
            'ip_address' => $context->ip,
            'user_agent' => $context->boundedUserAgent(),
            'request_id' => $context->boundedRequestId(),
            'metadata' => null,
            'recorded_at' => now(),
        ]);
    }
}
