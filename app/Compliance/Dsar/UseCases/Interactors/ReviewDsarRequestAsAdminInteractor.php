<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\Http\Values\AdminRequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ReviewDsarRequestAsAdminInteractor
{
    public function execute(string $requestId, User $admin, AdminRequestContext $context): DsarRequestRecord
    {
        $request = DsarRequestRecord::query()->whereKey($requestId)->first()
            ?? throw new ModelNotFoundException;

        $this->recordAdminRead($request, $admin, $context);

        return $request;
    }

    private function recordAdminRead(DsarRequestRecord $request, User $admin, AdminRequestContext $context): void
    {
        AuditEntry::query()->create([
            'company_id' => $request->company_id,
            'stored_event_id' => null,
            'event_class' => 'DsarRequestViewedByAdmin',
            'subject_type' => 'dsar_request',
            'subject_id' => $request->getKey(),
            'subject_label' => null,
            'action' => 'dsar_admin_viewed',
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
