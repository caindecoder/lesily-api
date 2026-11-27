<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Dsar\Events\DsarRequestCancelled;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Foundation\AuditTrail\UseCases\Queries\MemberRoleCode;
use App\Compliance\Dsar\UseCases\Queries\DsarRequestVisibility;
use App\Compliance\Dsar\UseCases\Commands\CancelDsarRequestCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class CancelDsarRequestInteractor
{
    public function __construct(
        private readonly DsarRequestVisibility $dsarRequestVisibility,
        private readonly MemberRoleCode $memberRoleCode,
    ) {}

    public function execute(CancelDsarRequestCommand $cancelDsarRequestCommand, User $actor, RequestOrigin $requestOrigin): DsarRequestRecord
    {
        $dsarRequest = $this->dsarRequestVisibility->visibleTo($actor, $cancelDsarRequestCommand->company)->whereKey($cancelDsarRequestCommand->dsarRequest->getKey())->first()
            ?? throw new ModelNotFoundException;

        return DB::transaction(function () use ($dsarRequest, $cancelDsarRequestCommand, $actor, $requestOrigin): DsarRequestRecord {
            DsarRequest::retrieve($dsarRequest->getKey())->cancel($cancelDsarRequestCommand)->persist();

            $withdrawnDsarRequest = DsarRequestRecord::query()->whereKey($dsarRequest->getKey())->firstOrFail();
            $this->recordWithdrawn($withdrawnDsarRequest, $actor, $requestOrigin);

            return $withdrawnDsarRequest;
        });
    }

    protected function recordWithdrawn(DsarRequestRecord $dsarRequest, User $actor, RequestOrigin $requestOrigin): void
    {
        AuditEntry::query()->create([
            'company_id' => $dsarRequest->company_id,
            'stored_event_id' => $this->cancelledEventIdFor($dsarRequest),
            'event_class' => 'DsarRequestCancelled',
            'subject_type' => AuditSubjectType::DsarRequest,
            'subject_id' => $dsarRequest->getKey(),
            'subject_label' => null,
            'action' => AuditAction::DsarWithdrawn,
            'description' => 'DSAR withdrawn — for: '.$dsarRequest->subject->name.', by: '.$actor->name,
            'actor_user_id' => $actor->getKey(),
            'actor_role_code' => $this->memberRoleCode->of($actor, $dsarRequest->company_id),
            'actor_label' => $actor->name,
            'ip_address' => $requestOrigin->ip,
            'user_agent' => $requestOrigin->boundedUserAgent(),
            'request_id' => $requestOrigin->boundedRequestId(),
            'metadata' => null,
            'recorded_at' => now(),
        ]);
    }

    protected function cancelledEventIdFor(DsarRequestRecord $dsarRequest): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $dsarRequest->getKey())
            ->where('event_class', DsarRequestCancelled::class)
            ->sole()
            ->getKey();
    }
}
