<?php

namespace App\Companies\Memberships\UseCases\Audit;

use App\Models\User;
use App\Companies\Entities\CompanyUser;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Values\FieldChanges;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\AuditTrail\UseCases\Audit\AuditTrailWriter;
use App\Foundation\AuditTrail\UseCases\Queries\MemberRoleCode;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * The audit rows about a member, written inside the caller's transaction.
 */
class MembershipAudit
{
    public function __construct(
        protected readonly MemberRoleCode $memberRoleCode,
        protected readonly AuditTrailWriter $writer,
    ) {}

    public function roleChanged(RoleChange $change, User $actor, RequestOrigin $origin): void
    {
        $membership = $change->membership->refresh();

        $this->writer->write([
            ...$this->rowOf($membership, $change->causeAggregateUuid),
            ...$this->writer->actorAndOrigin($actor, $change->actorRoleCode ?? $this->memberRoleCode->of($actor, $membership->company_id), $origin),
            'metadata' => [
                'membership_id' => $membership->getKey(),
                'changes' => ['role' => ['from' => $change->fromRoleCode, 'to' => $membership->role()->sole()->code]],
            ],
        ], AuditAction::RoleChanged, 'warning');
    }

    public function removed(CompanyUser $membership, User $actor, RequestOrigin $origin): void
    {
        $this->writer->write([
            ...$this->rowOf($membership, $membership->getKey()),
            ...$this->writer->actorAndOrigin($actor, $this->memberRoleCode->of($actor, $membership->company_id), $origin),
            'metadata' => [
                'membership_id' => $membership->getKey(),
                'role_code' => $membership->role()->sole()->code,
            ],
        ], AuditAction::MemberRemoved, 'warning');
    }

    public function emergencyContactSet(CompanyUser $membership, FieldChanges $changes, RequestOrigin $origin): void
    {
        if ($changes->isEmpty()) {
            return;
        }

        $owner = $membership->user()->sole();

        $this->writer->write([
            ...$this->rowOf($membership, $owner->getKey()),
            ...$this->writer->actorAndOrigin($owner, $this->memberRoleCode->of($owner, $membership->company_id), $origin),
            'metadata' => [
                'membership_id' => $membership->getKey(),
                'changes' => $changes->toArray(),
            ],
        ], AuditAction::EmergencyContactUpdated, 'info');
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
}
