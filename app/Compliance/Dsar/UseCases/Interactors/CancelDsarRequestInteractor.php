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
use App\Compliance\Dsar\UseCases\Queries\MemberRoleCode;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Dsar\UseCases\Queries\DsarRequestVisibility;
use App\Compliance\Dsar\UseCases\Commands\CancelDsarRequestCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class CancelDsarRequestInteractor
{
    public function __construct(
        private readonly DsarRequestVisibility $visibility,
        private readonly MemberRoleCode $memberRoleCode,
    ) {}

    public function execute(CancelDsarRequestCommand $command, User $actor, RequestOrigin $context): DsarRequestRecord
    {
        $request = $this->visibility->visibleTo($actor, $command->company)->whereKey($command->dsarRequest->getKey())->first()
            ?? throw new ModelNotFoundException;

        return DB::transaction(function () use ($request, $command, $actor, $context): DsarRequestRecord {
            DsarRequest::retrieve($request->getKey())->cancel($command)->persist();

            $withdrawn = DsarRequestRecord::query()->whereKey($request->getKey())->firstOrFail();
            $this->recordWithdrawn($withdrawn, $actor, $context);

            return $withdrawn;
        });
    }

    protected function recordWithdrawn(DsarRequestRecord $request, User $actor, RequestOrigin $context): void
    {
        AuditEntry::query()->create([
            'company_id' => $request->company_id,
            'stored_event_id' => $this->cancelledEventIdFor($request),
            'event_class' => 'DsarRequestCancelled',
            'subject_type' => AuditSubjectType::DsarRequest,
            'subject_id' => $request->getKey(),
            'subject_label' => null,
            'action' => AuditAction::DsarWithdrawn,
            'description' => 'DSAR withdrawn — for: '.$request->subject->name.', by: '.$actor->name,
            'actor_user_id' => $actor->getKey(),
            'actor_role_code' => $this->memberRoleCode->of($actor, $request->company_id),
            'actor_label' => $actor->name,
            'ip_address' => $context->ip,
            'user_agent' => $context->boundedUserAgent(),
            'request_id' => $context->boundedRequestId(),
            'metadata' => null,
            'recorded_at' => now(),
        ]);
    }

    protected function cancelledEventIdFor(DsarRequestRecord $request): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $request->getKey())
            ->where('event_class', DsarRequestCancelled::class)
            ->sole()
            ->getKey();
    }
}
