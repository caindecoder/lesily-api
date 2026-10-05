<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Compliance\Dsar\Events\DsarRequestCancelled;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Dsar\UseCases\Queries\DsarRequestVisibility;
use App\Compliance\Dsar\UseCases\Commands\CancelDsarRequestCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class CancelDsarRequestInteractor
{
    public function __construct(private readonly DsarRequestVisibility $visibility) {}

    public function execute(CancelDsarRequestCommand $command, User $actor, AdminRequestContext $context): DsarRequestRecord
    {
        $company = Company::query()->findOrFail($command->companyId);

        $request = $this->visibility->visibleTo($actor, $company)->whereKey($command->requestId)->first()
            ?? throw new ModelNotFoundException;

        return DB::transaction(function () use ($request, $command, $actor, $context): DsarRequestRecord {
            DsarRequest::retrieve($request->getKey())->cancel($command)->persist();

            $withdrawn = DsarRequestRecord::query()->findOrFail($request->getKey());
            $this->recordWithdrawn($withdrawn, $actor, $context);

            return $withdrawn;
        });
    }

    private function recordWithdrawn(DsarRequestRecord $request, User $actor, AdminRequestContext $context): void
    {
        AuditEntry::query()->create([
            'company_id' => $request->company_id,
            'stored_event_id' => $this->cancelledEventIdFor($request),
            'event_class' => 'DsarRequestCancelled',
            'subject_type' => 'dsar_request',
            'subject_id' => $request->getKey(),
            'subject_label' => null,
            'action' => 'dsar_withdrawn',
            'description' => 'DSAR withdrawn — for: '.$request->subject->name.', by: '.$actor->name,
            'actor_user_id' => $actor->getKey(),
            'actor_role_code' => $this->roleCodeOf($actor, $request->company_id),
            'actor_label' => $actor->name,
            'ip_address' => $context->ip,
            'user_agent' => $context->boundedUserAgent(),
            'request_id' => $context->boundedRequestId(),
            'metadata' => null,
            'recorded_at' => now(),
        ]);
    }

    private function roleCodeOf(User $actor, string $companyId): ?string
    {
        return Role::query()
            ->whereKey(CompanyUser::query()->where('company_id', $companyId)->where('user_id', $actor->getKey())->value('role_id'))
            ->value('code');
    }

    private function cancelledEventIdFor(DsarRequestRecord $request): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $request->getKey())
            ->where('event_class', DsarRequestCancelled::class)
            ->sole()
            ->id;
    }
}
