<?php

namespace App\Companies\Memberships\UseCases\Audit;

use App\Models\User;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Companies\Memberships\Entities\Invitation;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\AuditTrail\UseCases\Queries\MemberRoleCode;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * The audit rows of the invitation actions, written inside the caller's transaction.
 */
class InvitationAudit
{
    public function __construct(protected readonly MemberRoleCode $memberRoleCode) {}

    public function invited(Invitation $invitation, User $actor, RequestOrigin $origin): void
    {
        $this->write($this->rowOf($invitation, $actor, $origin), AuditAction::UserInvited);
    }

    /** @return array<string, mixed> */
    protected function rowOf(Invitation $invitation, User $actor, RequestOrigin $origin): array
    {
        $storedEvent = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $invitation->getKey())
            ->latest('id')
            ->firstOrFail();

        return [
            'company_id' => $invitation->company_id,
            'stored_event_id' => $storedEvent->getKey(),
            'event_class' => class_basename($storedEvent->event_class),
            'subject_type' => AuditSubjectType::Invitation,
            'subject_id' => $invitation->getKey(),
            'subject_label' => $invitation->email,
            'actor_user_id' => $actor->getKey(),
            'actor_role_code' => $this->memberRoleCode->of($actor, $invitation->company_id),
            'actor_label' => $actor->name,
            'ip_address' => $origin->ip,
            'user_agent' => $origin->boundedUserAgent(),
            'request_id' => $origin->boundedRequestId(),
            'metadata' => ['role_code' => $invitation->role()->sole()->code],
        ];
    }

    /** @param array<string, mixed> $row */
    protected function write(array $row, AuditAction $action): void
    {
        AuditEntry::query()->create([
            ...$row,
            'action' => $action,
            'description' => __('dsar.codes.action.'.$action->value),
            'severity' => 'info',
            'recorded_at' => now(),
        ]);
    }
}
