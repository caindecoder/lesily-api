<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use App\Companies\Memberships\Aggregates\UserMembership;
use App\Companies\Memberships\UseCases\Repositories\InvitationRepositoryInterface;

/**
 * The daily scan that expires every pending invitation past its window (T5,
 * System actor). The boundary (`invitations:expire`) hands it the cutoff; it
 * reads the due invitation ids from the repository and expires each through the
 * UserMembership aggregate. Delivery stays a thin appendix (E07).
 */
class ExpirePendingInvitationsInteractor
{
    public function __construct(
        private InvitationRepositoryInterface $invitations,
    ) {}

    public function execute(CarbonInterface $asOf): int
    {
        $expirable = $this->invitations->listExpirablePending($asOf);

        foreach ($expirable as $membershipId) {
            // One transaction per row so a projector failure rolls back the stored
            // event WITH its expired_at marker (ApplyTrialExpirationInteractor
            // parity) — otherwise a committed event without the marker would be
            // re-expired on the next scan (the guardless aggregate records again).
            DB::transaction(
                fn () => UserMembership::retrieve($membershipId)->expireIfPending($asOf)->persist(),
            );
        }

        return count($expirable);
    }
}
