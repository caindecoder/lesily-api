<?php

namespace App\Companies\OwnershipTransfers\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * SM-1-05 T4 (Verified → Executed). Terminal, and the only transition in this
 * module that moves anything outside its own aggregate: four $weight-ordered
 * projectors read this one payload and write four disjoint tables.
 *
 * The payload carries its FULL designed shape from the first write rather than
 * growing later: stored_events is append-only, so widening a payload in place is
 * an event-versioning problem (OQ-1-108), not a refactor.
 *
 * The six membership/role fields are load-bearing, not convenience (ruling P2).
 * `OwnershipTransferPivotHistoryProjector` writes the pivot-history rows from THIS payload
 * and never from `company_user`, because `MembershipProjector` has already
 * mutated that pivot by the time it runs — a projector reading the prior role
 * from the row would write `old_role_id == new_role_id` on every replay,
 * destroying the only record of the transition. Ruling P1 removed the
 * `UserMembershipRoleChanged` emission that used to carry it, and nothing else
 * does: `audit_trail` writes `metadata => NULL` by deliberate s149 ruling.
 *
 * `verificationNotes` rides forward from T2's own event on the STREAM rather
 * than being re-entered (ruling P3) or read from the projection (ruling P18,
 * s162) — the aggregate captures it at apply-time, so the one forensic field
 * here is a pure function of the events and immune to projection lag. SM-1-05
 * §7 puts it on this payload; VerifyOwnershipTransferIdentityCommand makes it
 * mandatory at T2.
 *
 * Every field is a scalar and stays spelled out here — array shapes in this
 * module are written INLINE and never behind a `@phpstan-type` alias, which at
 * s149 made the serializer resolve a payload field as a non-existent class, so
 * the event wrote but could never be replayed (C3).
 */
class OwnershipTransferExecuted extends ShouldBeStored
{
    public function __construct(
        public string $requestId,
        public string $companyId,
        public string $oldOwnerUserId,
        public string $newOwnerUserId,
        public string $oldOwnerMembershipId,
        public string $oldOwnerOldRoleId,
        public string $oldOwnerNewRoleId,
        public string $newOwnerMembershipId,
        public string $newOwnerOldRoleId,
        public string $newOwnerNewRoleId,
        public string $executedBy,
        public string $reason,
        public ?string $verificationNotes,
        public CarbonInterface $executedAt,
    ) {}
}
