<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use Carbon\CarbonInterface;
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
            UserMembership::retrieve($membershipId)->expireIfPending($asOf)->persist();
        }

        return count($expirable);
    }
}
