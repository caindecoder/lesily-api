<?php

namespace App\Companies\OwnershipTransfers\Reactors;

use App\Models\User;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExpired;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExecuted;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRejected;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferCancelled;
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
    /**
     * The human-readable subject every row in this table shares — one aggregate,
     * so one label, whichever transition wrote the row. Extracted at the s153
     * SonarCloud round (php:S1192, the s118 UC-1-034 precedent): three literals
     * that must agree are three chances to disagree, and a tenant reads this
     * string back through UC-1-066. All six transition arms share it.
     */
    private const SUBJECT_LABEL = 'Ownership transfer request';

    public function onOwnershipTransferRequested(OwnershipTransferRequested $event): void
    {
        AuditEntry::query()->create([
            'company_id' => $event->companyId,
            'stored_event_id' => $event->storedEventId(),
            'event_class' => 'OwnershipTransferRequested',
            'subject_type' => 'ownership_transfer_request',
            'subject_id' => $event->requestId,
            'subject_label' => self::SUBJECT_LABEL,
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
            'subject_label' => self::SUBJECT_LABEL,
            'action' => 'transfer_identity_verified',
            'description' => 'Platform admin verified the requester identity; the transfer is ready to execute.',
            'actor_user_id' => $event->verifiedBy,
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => User::query()->whereKey($event->verifiedBy)->value('name'),
            'recorded_at' => now(),
        ]);
    }

    /**
     * T3/T5. The actor is the point of this row — it records WHICH platform admin
     * ended a tenant's transfer, which is the evidence a later "the rejection was
     * arbitrary" claim turns on (UC-1-128 stakeholders: Lesily Legal). The tenant
     * is read off the projection, since the event carries only the request id.
     *
     * metadata stays NULL per the s149 ruling: audit_trail rows are TENANT-readable
     * via UC-1-066, and the durable forensic record is the event itself, reachable
     * from here through stored_event_id.
     */
    public function onOwnershipTransferRejected(OwnershipTransferRejected $event): void
    {
        $transfer = OwnershipTransferRequest::query()->findOrFail($event->requestId);

        AuditEntry::query()->create([
            'company_id' => $transfer->company_id,
            'stored_event_id' => $event->storedEventId(),
            'event_class' => 'OwnershipTransferRejected',
            'subject_type' => 'ownership_transfer_request',
            'subject_id' => $event->requestId,
            'subject_label' => self::SUBJECT_LABEL,
            'action' => 'transfer_rejected',
            'description' => 'Platform admin rejected the ownership transfer; the current owner retains ownership.',
            'actor_user_id' => $event->rejectedBy,
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => User::query()->whereKey($event->rejectedBy)->value('name'),
            'recorded_at' => now(),
        ]);
    }

    /**
     * SM-1-05 T6 + T7 (UC-1-114 / UC-1-115). The tenant-side counterpart of the
     * rejection row: the actor here is the REQUESTER, not a platform admin, and
     * the role code says so. Lesily Legal's chain-of-custody has to read "the
     * requester withdrew it" rather than merely "it stopped" — the withdrawal is
     * evidence the owner did NOT transfer authority.
     *
     * One arm for both windows: the `source` discriminator lives on the event and
     * the projection, and the audit row's job is attribution, not restating the
     * state machine.
     *
     * metadata stays NULL per the s149 ruling: audit_trail rows are TENANT-readable
     * via UC-1-066, and the durable forensic record is the event itself, reachable
     * from here through stored_event_id.
     */
    public function onOwnershipTransferCancelled(OwnershipTransferCancelled $event): void
    {
        $transfer = OwnershipTransferRequest::query()->findOrFail($event->requestId);

        AuditEntry::query()->create([
            'company_id' => $transfer->company_id,
            'stored_event_id' => $event->storedEventId(),
            'event_class' => 'OwnershipTransferCancelled',
            'subject_type' => 'ownership_transfer_request',
            'subject_id' => $event->requestId,
            'subject_label' => self::SUBJECT_LABEL,
            'action' => 'transfer_withdrawn',
            'description' => 'The requester withdrew the ownership transfer; the current owner retains ownership.',
            'actor_user_id' => $event->cancelledBy,
            'actor_role_code' => 'owner',
            'actor_label' => User::query()->whereKey($event->cancelledBy)->value('name'),
            'recorded_at' => now(),
        ]);
    }

    /**
     * SM-1-05 T4 (UC-1-093). The row UC-1-093's Minimal Guarantee names: no role
     * swap succeeds without a corresponding audit record. Every other transition
     * here records something that did NOT move ownership; this is the one that
     * did, which makes it the evidence a later "that transfer was unauthorised"
     * claim turns on (UC-1-093 stakeholders: Lesily Legal).
     *
     * The only arm that does NOT read the projection: `OwnershipTransferExecuted`
     * carries `companyId` on the payload (ruling P2 put it there for the pivot
     * history), so the tenant is already in hand — the `onOwnershipTransferRequested`
     * shape rather than the four that must look the row up.
     *
     * metadata stays NULL per the s149 ruling, and deliberately so here despite
     * this row having the most to say: the reason, both parties and both role
     * transitions are all on the event, reachable through stored_event_id, and
     * audit_trail is TENANT-readable via UC-1-066. The durable forensic record is
     * the event itself.
     */
    public function onOwnershipTransferExecuted(OwnershipTransferExecuted $event): void
    {
        AuditEntry::query()->create([
            'company_id' => $event->companyId,
            'stored_event_id' => $event->storedEventId(),
            'event_class' => 'OwnershipTransferExecuted',
            'subject_type' => 'ownership_transfer_request',
            'subject_id' => $event->requestId,
            'subject_label' => self::SUBJECT_LABEL,
            'action' => 'transfer_executed',
            'description' => 'Platform admin executed the ownership transfer; ownership moved to the new owner.',
            'actor_user_id' => $event->executedBy,
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => User::query()->whereKey($event->executedBy)->value('name'),
            'recorded_at' => now(),
        ]);
    }

    /**
     * SM-1-05 T8 + T9 (UC-1-116 / UC-1-117). The app's FIRST system-actor audit
     * row, and the precedent for every scheduled write that follows.
     *
     * All three actor columns are NULL (ruling M7). No human acted, so naming one
     * would be false; and a sentinel like `'system'` in `actor_role_code` would
     * put a value in that column which the RBAC catalog does not contain — on a
     * feed UC-1-066 exposes to tenants. "The system did it" is carried by the
     * absent actor together with the `transfer_expired` verb.
     *
     * One arm for both windows, and the description does not name the stage —
     * the same reasoning the withdrawal arm records: the audit row's job is
     * attribution, not restating the state machine. Which window expired lives on
     * the event and the projection, and `stored_event_id` is the route to it.
     */
    public function onOwnershipTransferExpired(OwnershipTransferExpired $event): void
    {
        $transfer = OwnershipTransferRequest::query()->findOrFail($event->requestId);

        AuditEntry::query()->create([
            'company_id' => $transfer->company_id,
            'stored_event_id' => $event->storedEventId(),
            'event_class' => 'OwnershipTransferExpired',
            'subject_type' => 'ownership_transfer_request',
            'subject_id' => $event->requestId,
            'subject_label' => self::SUBJECT_LABEL,
            'action' => 'transfer_expired',
            'description' => 'The ownership transfer expired before it was completed; the current owner retains ownership.',
            'actor_user_id' => null,
            'actor_role_code' => null,
            'actor_label' => null,
            'recorded_at' => now(),
        ]);
    }
}
