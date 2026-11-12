<?php

namespace App\Foundation\AuditTrail\UseCases\Audit;

use stdClass;
use App\Models\User;
use Illuminate\Support\Collection;
use App\Companies\Entities\CompanyUser;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * The audit rows of an action on a user's own account: one per company the user is an
 * active member of, written inside the caller's transaction.
 */
class UserLevelAudit
{
    public function record(User $user, AuditAction $action, RequestOrigin $origin): void
    {
        $storedEvent = $this->newestEventOf($user);

        foreach ($this->membershipsOf($user) as $membership) {
            AuditEntry::query()->create([
                'company_id' => $membership->company_id,
                'stored_event_id' => $storedEvent->getKey(),
                'event_class' => class_basename($storedEvent->event_class),
                'subject_type' => AuditSubjectType::User,
                'subject_id' => $user->getKey(),
                'subject_label' => null,
                'action' => $action,
                'description' => __('dsar.codes.action.'.$action->value),
                'actor_user_id' => $user->getKey(),
                'actor_role_code' => $membership->role_code,
                'actor_label' => $user->name,
                'ip_address' => $origin->ip,
                'user_agent' => $origin->boundedUserAgent(),
                'request_id' => $origin->boundedRequestId(),
                'metadata' => null,
                'severity' => 'warning',
                'recorded_at' => now(),
            ]);
        }
    }

    protected function newestEventOf(User $user): LesilyStoredEvent
    {
        /** @var LesilyStoredEvent $event */
        $event = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $user->getKey())
            ->latest('id')
            ->firstOrFail();

        return $event;
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
