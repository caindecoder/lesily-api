<?php

namespace App\Foundation\AuditTrail\UseCases\Audit;

use Closure;
use stdClass;
use App\Models\User;
use Illuminate\Support\Collection;
use App\Companies\Entities\CompanyUser;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Values\FieldChanges;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * The audit rows of an action on a user's own account: one per company the user is an
 * active member of, written inside the caller's transaction.
 */
class UserLevelAudit
{
    public function __construct(protected readonly AuditTrailWriter $auditTrailWriter) {}

    public function record(User $user, AuditAction $auditAction, RequestOrigin $requestOrigin): void
    {
        $this->writeForEachMembership($user, $requestOrigin, fn (array $row) => $this->auditTrailWriter->write(
            [...$row, 'metadata' => null],
            $auditAction,
            'warning',
        ));
    }

    public function profileUpdated(User $user, FieldChanges $fieldChanges, RequestOrigin $requestOrigin): void
    {
        $this->writeForEachMembership($user, $requestOrigin, fn (array $row) => $this->auditTrailWriter->write(
            [...$row, 'metadata' => ['changes' => $fieldChanges->toArray()]],
            AuditAction::ProfileUpdated,
            'info',
        ));
    }

    /** @param Closure(array<string, mixed>): void $write */
    protected function writeForEachMembership(User $user, RequestOrigin $requestOrigin, Closure $write): void
    {
        $eloquentStoredEvent = $this->newestEventOf($user);

        foreach ($this->membershipsOf($user) as $stdClass) {
            $write([
                'company_id' => $stdClass->company_id,
                'stored_event_id' => $eloquentStoredEvent->getKey(),
                'event_class' => class_basename($eloquentStoredEvent->event_class),
                'subject_type' => AuditSubjectType::User,
                'subject_id' => $user->getKey(),
                'subject_label' => null,
                ...$this->auditTrailWriter->actorAndOrigin($user, $stdClass->role_code, $requestOrigin),
            ]);
        }
    }

    protected function newestEventOf(User $user): EloquentStoredEvent
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $user->getKey())
            ->latest('id')
            ->firstOrFail();
    }

    /** @return Collection<int, stdClass> */
    protected function membershipsOf(User $user): Collection
    {
        return CompanyUser::query()
            ->leftJoin('roles', 'roles.id', '=', 'company_user.role_id')
            ->where('company_user.user_id', $user->getKey())
            ->toBase()
            ->get(['company_user.company_id', 'roles.code as role_code']);
    }
}
