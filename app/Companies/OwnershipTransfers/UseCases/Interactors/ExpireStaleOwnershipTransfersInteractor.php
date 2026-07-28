<?php

namespace App\Companies\OwnershipTransfers\UseCases\Interactors;

use Carbon\CarbonInterface;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\UseCases\Commands\ExpireOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;
use App\Companies\OwnershipTransfers\Aggregates\OwnershipTransferRequest as OwnershipTransferRequestAggregate;

/**
 * The daily batch that expires every transfer past its SLA window (SM-1-05
 * T8/T9, System actor). The boundary hands it the cutoff; it reads the due rows
 * from the repository and ends each one through the aggregate. Delivery stays a
 * thin appendix (E07).
 *
 * Named for the rows it selects, matching the two built scans
 * (`ExpireElapsedTrialsInteractor`, `ExpirePendingInvitationsInteractor`) — the
 * qualifier is the point (ruling M5).
 *
 * No `DB::transaction` per row, unlike `ExpirePendingInvitationsInteractor`
 * (ruling M4). That precedent wraps to stop a committed event without its
 * projection marker being re-expired on the next scan — a hazard that exists
 * only because ITS aggregate is guardless. Ours is not: `expireIfStale()`
 * replays the stream and no-ops once the state has left the open set, so a
 * second event cannot be recorded however often the row is re-selected. The
 * remaining exposure (a projector failure leaving the row divergent) is
 * pre-existing and module-wide — withdraw and reject carry it identically — and
 * is logged to Task 9 beside E-f and OQ-1-153 rather than swept in from here.
 */
class ExpireStaleOwnershipTransfersInteractor
{
    public function __construct(
        private OwnershipTransferRequestRepositoryInterface $transfers,
    ) {}

    public function execute(CarbonInterface $asOf): int
    {
        $expired = 0;

        foreach ($this->transfers->listTimerReady($asOf) as $requestId => $status) {
            OwnershipTransferRequestAggregate::retrieve($requestId)
                ->expireIfStale(ExpireOwnershipTransferCommand::validateAndCreate([
                    'requestId' => $requestId,
                    'expiredAfterDays' => $this->windowFor($status),
                ]))
                ->persist();

            $expired++;
        }

        return $expired;
    }

    /**
     * The SLA window the row broke, chosen by its PRE-transition state — the
     * policy number, not measured elapsed time (ruling M2). Config-driven so the
     * windows stay tunable without a deploy (OQ-1-006 locks the defaults).
     */
    private function windowFor(OwnershipTransferStatus $status): int
    {
        return (int) match ($status) {
            OwnershipTransferStatus::Requested => config('ownership-transfers.request_ttl_days', 30),
            default => config('ownership-transfers.verified_ttl_days', 14),
        };
    }
}
