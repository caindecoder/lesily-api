<?php

namespace App\Companies\Memberships\UseCases\Audit;

use App\Models\User;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Companies\Memberships\Entities\Invitation;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\AuditTrail\UseCases\Audit\AuditTrailWriter;
use App\Foundation\AuditTrail\UseCases\Queries\MemberRoleCode;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * The audit rows of the invitation actions: each invitation's row inside the
 * caller's transaction; a bulk invite's batch row on its own, after its rows.
 */
class InvitationAudit
{
    public function __construct(
        protected readonly MemberRoleCode $memberRoleCode,
        protected readonly AuditTrailWriter $writer,
    ) {}

    public function invited(Invitation $invitation, User $actor, RequestOrigin $origin): void
    {
        $this->write($this->rowOf($invitation, $actor, $origin), AuditAction::UserInvited);
    }

    public function resent(Invitation $invitation, User $actor, RequestOrigin $origin): void
    {
        $this->write($this->rowOf($invitation, $actor, $origin), AuditAction::InvitationResent);
    }

    public function revoked(Invitation $invitation, User $actor, RequestOrigin $origin): void
    {
        $this->write($this->rowOf($invitation, $actor, $origin), AuditAction::InvitationRevoked);
    }

    public function accepted(Invitation $invitation, User $joiner, RequestOrigin $origin): void
    {
        $this->write($this->rowOf($invitation, $joiner, $origin), AuditAction::InvitationAccepted);
    }

    public function bulkInviteSent(BulkInviteSent $batch, User $actor, RequestOrigin $origin): void
    {
        $this->write([
            ...$this->actorAndOrigin($actor, $batch->company->getKey(), $origin),
            'company_id' => $batch->company->getKey(),
            'stored_event_id' => null,
            'event_class' => 'BulkInviteSent',
            'subject_type' => AuditSubjectType::Company,
            'subject_id' => $batch->company->getKey(),
            'subject_label' => null,
            'metadata' => ['count' => $batch->count],
        ], AuditAction::BulkInviteSent);
    }

    /** @return array<string, mixed> */
    protected function rowOf(Invitation $invitation, User $actor, RequestOrigin $origin): array
    {
        $storedEvent = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $invitation->getKey())
            ->latest('id')
            ->firstOrFail();

        return [
            ...$this->actorAndOrigin($actor, $invitation->company_id, $origin),
            'company_id' => $invitation->company_id,
            'stored_event_id' => $storedEvent->getKey(),
            'event_class' => class_basename($storedEvent->event_class),
            'subject_type' => AuditSubjectType::Invitation,
            'subject_id' => $invitation->getKey(),
            'subject_label' => $invitation->email,
            'metadata' => ['role_code' => $invitation->role()->sole()->code],
        ];
    }

    /** @return array<string, mixed> */
    protected function actorAndOrigin(User $actor, string $companyId, RequestOrigin $origin): array
    {
        return $this->writer->actorAndOrigin($actor, $this->memberRoleCode->of($actor, $companyId), $origin);
    }

    /** @param array<string, mixed> $row */
    protected function write(array $row, AuditAction $action): void
    {
        $this->writer->write($row, $action, 'info');
    }
}
