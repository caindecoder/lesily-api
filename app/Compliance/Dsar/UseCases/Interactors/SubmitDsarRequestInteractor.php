<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Compliance\Dsar\Events\DsarRequestSubmitted;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Foundation\Http\Exceptions\RoleInsufficientException;
use App\Foundation\RolesPermissions\Services\PermissionEvaluator;
use App\Compliance\Dsar\Exceptions\DsarRequestInProgressException;
use App\Compliance\Dsar\UseCases\Commands\SubmitDsarRequestCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Dsar\UseCases\Repositories\DsarRequestRepositoryInterface;

class SubmitDsarRequestInteractor
{
    public const string REQUEST_FOR_OTHERS = 'dsar.request-for-others';

    public function __construct(
        private readonly PermissionEvaluator $permissions,
        private readonly DsarRequestRepositoryInterface $requests,
    ) {}

    public function execute(SubmitDsarRequestCommand $command, User $actor, AdminRequestContext $context): DsarRequestRecord
    {
        $company = Company::query()->findOrFail($command->companyId);

        if ($command->isForSomeoneElse() && ! $this->permissions->canUserDo($actor, $company, self::REQUEST_FOR_OTHERS)) {
            throw new RoleInsufficientException(self::REQUEST_FOR_OTHERS);
        }

        if ($this->liveMembership($command->companyId, $command->subjectUserId()) === null) {
            throw new ModelNotFoundException;
        }

        try {
            return DB::transaction(fn () => $this->record($command, $actor, $context));
        } catch (UniqueConstraintViolationException) {
            throw new DsarRequestInProgressException;
        }
    }

    private function record(SubmitDsarRequestCommand $command, User $actor, AdminRequestContext $context): DsarRequestRecord
    {
        if ($this->requests->findOpenFor($command->companyId, $command->subjectUserId()) !== null) {
            throw new DsarRequestInProgressException;
        }

        $requestId = Str::uuid7()->toString();
        $requestedByRole = $this->roleCodeOf($command->companyId, $actor);

        DsarRequest::retrieve($requestId)
            ->submit($command, $requestedByRole)
            ->persist();

        $request = DsarRequestRecord::query()->with(['subject', 'requestedBy'])->findOrFail($requestId);
        $this->recordRequested($request, $actor, $context);

        return $request;
    }

    private function recordRequested(DsarRequestRecord $request, User $actor, AdminRequestContext $context): void
    {
        AuditEntry::query()->create([
            'company_id' => $request->company_id,
            'stored_event_id' => $this->submittedEventIdFor($request),
            'event_class' => 'DsarRequestSubmitted',
            'subject_type' => 'dsar_request',
            'subject_id' => $request->getKey(),
            'subject_label' => null,
            'action' => 'dsar_requested',
            'description' => 'DSAR requested — for: '.$request->subject->name.', by: '.$actor->name,
            'actor_user_id' => $actor->getKey(),
            'actor_role_code' => $request->requested_by_role,
            'actor_label' => $actor->name,
            'ip_address' => $context->ip,
            'user_agent' => $context->boundedUserAgent(),
            'request_id' => $context->boundedRequestId(),
            'metadata' => null,
            'recorded_at' => now(),
        ]);
    }

    private function submittedEventIdFor(DsarRequestRecord $request): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $request->getKey())
            ->where('event_class', DsarRequestSubmitted::class)
            ->sole()
            ->id;
    }

    private function liveMembership(string $companyId, string $userId): ?CompanyUser
    {
        return CompanyUser::query()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->first();
    }

    private function roleCodeOf(string $companyId, User $actor): string
    {
        return Role::query()
            ->whereKey($this->liveMembership($companyId, $actor->getKey())?->role_id)
            ->value('code');
    }
}
