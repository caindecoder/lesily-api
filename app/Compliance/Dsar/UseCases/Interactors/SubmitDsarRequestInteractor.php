<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\CompanyUser;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Dsar\Events\DsarRequestSubmitted;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Foundation\Http\Exceptions\RoleInsufficientException;
use App\Foundation\RolesPermissions\Services\PermissionEvaluator;
use App\Compliance\Dsar\Exceptions\DsarRequestInProgressException;
use App\Compliance\Dsar\UseCases\Commands\SubmitDsarRequestCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class SubmitDsarRequestInteractor
{
    public const string REQUEST_FOR_OTHERS = 'dsar.request-for-others';

    public function __construct(private readonly PermissionEvaluator $permissionEvaluator) {}

    public function execute(SubmitDsarRequestCommand $submitDsarRequestCommand, User $actor, RequestOrigin $requestOrigin): DsarRequestRecord
    {
        $company = $submitDsarRequestCommand->company;

        if ($submitDsarRequestCommand->isForSomeoneElse() && ! $this->permissionEvaluator->canUserDo($actor, $company, self::REQUEST_FOR_OTHERS)) {
            throw new RoleInsufficientException(self::REQUEST_FOR_OTHERS);
        }

        $this->liveMembership($company->getKey(), $submitDsarRequestCommand->resolvedSubjectUserId());

        try {
            return DB::transaction(fn () => $this->record($submitDsarRequestCommand, $actor, $requestOrigin));
        } catch (UniqueConstraintViolationException) {
            throw new DsarRequestInProgressException;
        }
    }

    protected function record(SubmitDsarRequestCommand $submitDsarRequestCommand, User $actor, RequestOrigin $requestOrigin): DsarRequestRecord
    {
        $requestId = Str::uuid7()->toString();
        $requestedByRole = Role::query()
            ->whereKey($this->liveMembership($submitDsarRequestCommand->company->getKey(), $actor->getKey())->role_id)
            ->value('code');

        DsarRequest::retrieve($requestId)
            ->submit($submitDsarRequestCommand, $requestedByRole)
            ->persist();

        $dsarRequest = DsarRequestRecord::query()->findOrFail($requestId);
        $this->recordRequested($dsarRequest, $actor, $requestOrigin);

        return $dsarRequest;
    }

    protected function recordRequested(DsarRequestRecord $dsarRequest, User $actor, RequestOrigin $requestOrigin): void
    {
        AuditEntry::query()->create([
            'company_id' => $dsarRequest->company_id,
            'stored_event_id' => $this->submittedEventIdFor($dsarRequest),
            'event_class' => 'DsarRequestSubmitted',
            'subject_type' => AuditSubjectType::DsarRequest,
            'subject_id' => $dsarRequest->getKey(),
            'subject_label' => null,
            'action' => AuditAction::DsarRequested,
            'description' => 'DSAR requested — for: '.$dsarRequest->subject->name.', by: '.$actor->name,
            'actor_user_id' => $actor->getKey(),
            'actor_role_code' => $dsarRequest->requested_by_role,
            'actor_label' => $actor->name,
            'ip_address' => $requestOrigin->ip,
            'user_agent' => $requestOrigin->boundedUserAgent(),
            'request_id' => $requestOrigin->boundedRequestId(),
            'metadata' => null,
            'recorded_at' => now(),
        ]);
    }

    protected function submittedEventIdFor(DsarRequestRecord $dsarRequest): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $dsarRequest->getKey())
            ->where('event_class', DsarRequestSubmitted::class)
            ->sole()
            ->getKey();
    }

    /**
     * A removed (soft-deleted) membership is not live; a missing one answers
     * 404 through ModelNotFoundException.
     */
    protected function liveMembership(string $companyId, string $userId): CompanyUser
    {
        return CompanyUser::query()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->firstOrFail();
    }
}
