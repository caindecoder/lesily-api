<?php

namespace App\Companies\Memberships\UseCases\Audit;

use App\Models\User;
use App\Companies\Entities\CompanyUser;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\AuditTrail\UseCases\Queries\MemberRoleCode;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * The audit rows about a member, written inside the caller's transaction.
 */
class MembershipAudit
{
    public function __construct(protected readonly MemberRoleCode $memberRoleCode) {}

    public function roleChanged(RoleChange $change, User $actor, RequestOrigin $origin): void
    {
        $membership = $change->membership->refresh();

        $this->write([
            ...$this->rowOf($membership, $change->causeAggregateUuid),
            ...$this->actorAndOrigin($actor, $change->actorRoleCode ?? $this->memberRoleCode->of($actor, $membership->company_id), $origin),
            'metadata' => [
                'membership_id' => $membership->getKey(),
                'changes' => ['role' => ['from' => $change->fromRoleCode, 'to' => $membership->role()->sole()->code]],
            ],
        ], AuditAction::RoleChanged);
    }

    public function removed(CompanyUser $membership, User $actor, RequestOrigin $origin): void
    {
        $this->write([
            ...$this->rowOf($membership, $membership->getKey()),
            ...$this->actorAndOrigin($actor, $this->memberRoleCode->of($actor, $membership->company_id), $origin),
            'metadata' => [
                'membership_id' => $membership->getKey(),
                'role_code' => $membership->role()->sole()->code,
            ],
        ], AuditAction::MemberRemoved);
    }

    /** @return array<string, mixed> */
    protected function rowOf(CompanyUser $membership, string $causeAggregateUuid): array
    {
        $storedEvent = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $causeAggregateUuid)
            ->latest('id')
            ->firstOrFail();
        $member = $membership->user()->sole();

        return [
            'company_id' => $membership->company_id,
            'stored_event_id' => $storedEvent->getKey(),
            'event_class' => class_basename($storedEvent->event_class),
            'subject_type' => AuditSubjectType::User,
            'subject_id' => $member->getKey(),
            'subject_label' => $member->name,
        ];
    }

    /** @return array<string, mixed> */
    protected function actorAndOrigin(User $actor, ?string $actorRoleCode, RequestOrigin $origin): array
    {
        return [
            'actor_user_id' => $actor->getKey(),
            'actor_role_code' => $actorRoleCode,
            'actor_label' => $actor->name,
            'ip_address' => $origin->ip,
            'user_agent' => $origin->boundedUserAgent(),
            'request_id' => $origin->boundedRequestId(),
        ];
    }

    /** @param array<string, mixed> $row */
    protected function write(array $row, AuditAction $action): void
    {
        AuditEntry::query()->create([
            ...$row,
            'action' => $action,
            'description' => __('dsar.codes.action.'.$action->value),
            'severity' => 'warning',
            'recorded_at' => now(),
        ]);
    }
}
