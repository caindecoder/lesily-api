<?php

namespace App\Companies\OwnershipTransfers\UseCases\Interactors;

use Carbon\CarbonInterface;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;

/**
 * The daily batch that expires every transfer past its SLA window (SM-1-05
 * T8/T9, System actor). The boundary hands it the cutoff; it reads the due ids
 * from the repository and ends each one through the aggregate.
 *
 * Named for the rows it selects, matching the two built scans
 * (`ExpireElapsedTrialsInteractor`, `ExpirePendingInvitationsInteractor`) — the
 * qualifier is the point (ruling M5).
 */
class ExpireStaleOwnershipTransfersInteractor
{
    public function __construct(
        private OwnershipTransferRequestRepositoryInterface $transfers,
    ) {}

    public function execute(CarbonInterface $asOf): int
    {
        $due = $this->transfers->listTimerReady($asOf);

        return count($due);
    }
}
