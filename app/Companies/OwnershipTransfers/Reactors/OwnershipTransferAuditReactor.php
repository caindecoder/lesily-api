<?php

namespace App\Companies\OwnershipTransfers\Reactors;

use App\Models\User;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRequested;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferIdentityVerified;

/**
 * Writes the append-only audit_trail row for each transfer transition
 * (SM-1-05 §6 audit-immutability). Module-local by design: the generic
 * AuditTrailReactor machinery stays reserved for the UC-1-066 slice
 * (AuditEntry docblock); the field shape mirrors the UC-1-122 direct-insert
 * precedent, plus the stored-event linkage an event-driven writer can carry.
 */
class OwnershipTransferAuditReactor extends Reactor
{
    public function onOwnershipTransferRequested(OwnershipTransferRequested $event): void
    {
        AuditEntry::query()->create([
            'company_id' => $event->companyId,
            'stored_event_id' => $event->storedEventId(),
            'event_class' => 'OwnershipTransferRequested',
            'subject_type' => 'ownership_transfer_request',
            'subject_id' => $event->requestId,
            'subject_label' => 'Ownership transfer request',
            'action' => 'transfer_requested',
            'description' => 'Owner requested an ownership transfer (support verification pending).',
            'actor_user_id' => $event->currentOwnerUserId,
            'actor_role_code' => 'owner',
            'actor_label' => User::query()->whereKey($event->currentOwnerUserId)->value('name'),
            'recorded_at' => now(),
        ]);
    }

    /**
     * T2. The actor is the whole point of this row — it records WHICH platform
     * admin vouched for the identity, which is the evidence a later disputed
     * transfer turns on (UC-1-113 stakeholders: Lesily Legal + the new owner).
     * The tenant is read off the projection, since the event carries only the
     * request id.
     */
    public function onOwnershipTransferIdentityVerified(OwnershipTransferIdentityVerified $event): void
    {
        $transfer = OwnershipTransferRequest::query()->findOrFail($event->requestId);

        AuditEntry::query()->create([
            'company_id' => $transfer->company_id,
            'stored_event_id' => $event->storedEventId(),
            'event_class' => 'OwnershipTransferIdentityVerified',
            'subject_type' => 'ownership_transfer_request',
            'subject_id' => $event->requestId,
            'subject_label' => 'Ownership transfer request',
            'action' => 'transfer_identity_verified',
            'description' => 'Platform admin verified the requester identity; the transfer is ready to execute.',
            'actor_user_id' => $event->verifiedBy,
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => User::query()->whereKey($event->verifiedBy)->value('name'),
            'recorded_at' => now(),
        ]);
    }
}
