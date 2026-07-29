<?php

namespace App\Companies\OwnershipTransfers\Projectors;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExecuted;

/**
 * Sole writer of ownership_transfer_pivot_history (ERD-1-05 §3, ruling P2) —
 * the T4 role-transition record, two rows per executed transfer.
 *
 * It writes that table and NOTHING else. In particular it does not touch
 * company_user: MembershipProjector is that table's sole writer (ruling P1),
 * and the design docs that had this class updating the pivot rows were
 * corrected at the s159 pre-flight.
 *
 * $weight 40 — last of the four T4 projectors (ruling P4).
 */
class CompanyUserPivotProjector extends Projector
{
    public int $weight = 40;

    /**
     * Both role ids come off the EVENT PAYLOAD, never from company_user.
     *
     * That is the whole design of this table rather than an optimisation: by the
     * time this projector runs, MembershipProjector (weight 20) has already
     * overwritten role_id in place, so a projector reading the prior role from
     * the pivot would write old_role_id == new_role_id on every replay —
     * silently destroying the one fact the table exists to hold.
     *
     * updateOrCreate on (request_id, membership_id) so a projection rebuild
     * overwrites rather than duplicates. Correct here precisely because this IS
     * a derived read model, and the deliberate inverse of audit_trail's
     * append-only posture (R5).
     */
    public function onOwnershipTransferExecuted(OwnershipTransferExecuted $event): void
    {
        $executedAt = CarbonImmutable::instance($event->executedAt);

        $this->recordTransition(
            $event,
            $event->oldOwnerMembershipId,
            [
                'user_id' => $event->oldOwnerUserId,
                'old_role_id' => $event->oldOwnerOldRoleId,
                'new_role_id' => $event->oldOwnerNewRoleId,
                'executed_at' => $executedAt,
            ],
        );

        $this->recordTransition(
            $event,
            $event->newOwnerMembershipId,
            [
                'user_id' => $event->newOwnerUserId,
                'old_role_id' => $event->newOwnerOldRoleId,
                'new_role_id' => $event->newOwnerNewRoleId,
                'executed_at' => $executedAt,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $transition
     */
    private function recordTransition(OwnershipTransferExecuted $event, string $membershipId, array $transition): void
    {
        DB::table('ownership_transfer_pivot_history')->updateOrInsert(
            ['request_id' => $event->requestId, 'membership_id' => $membershipId],
            fn (bool $exists): array => [
                ...$transition,
                'company_id' => $event->companyId,
                'updated_at' => CarbonImmutable::now(),
                // Identity is minted ONCE, on the insert branch only.
                //
                // `updateOrInsert` applies its whole $values array on the UPDATE
                // branch, so leaving these here re-keyed every row on every
                // projection rebuild and restamped its creation date — on the
                // table that exists to be forensic evidence. A projector must be
                // a deterministic function of the stream (§2.15); this one was
                // not, and the row-count/role-pair replay assertions could not
                // see it because both survive a re-key intact.
                //
                // `updated_at` stays on both branches deliberately: it records
                // when the projection was last written, so a replay moving it is
                // honest rather than a falsification.
                ...($exists ? [] : [
                    'id' => (string) Str::uuid7(),
                    'created_at' => CarbonImmutable::now(),
                ]),
            ],
        );
    }
}
