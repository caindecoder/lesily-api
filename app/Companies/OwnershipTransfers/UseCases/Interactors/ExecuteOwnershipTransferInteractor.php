<?php

namespace App\Companies\OwnershipTransfers\UseCases\Interactors;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\UseCases\Commands\ExecuteOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;
use App\Companies\OwnershipTransfers\Aggregates\OwnershipTransferRequest as OwnershipTransferRequestAggregate;

/**
 * SM-1-05 T4 (UC-1-093) — the platform admin executes a verified transfer and
 * ownership moves atomically across four tables.
 *
 * The unscoped find comes first, exactly as its verify and reject siblings do:
 * an unknown id and a denied caller must be byte-identical 404s, so the lookup
 * cannot be deferred behind anything that would answer differently.
 */
class ExecuteOwnershipTransferInteractor
{
    public function __construct(private readonly OwnershipTransferRequestRepositoryInterface $transfers) {}

    public function execute(string $requestId, ExecuteOwnershipTransferCommand $command): OwnershipTransferRequest
    {
        $transfer = $this->transfers->findByIdAcrossTenants($requestId);

        if ($transfer === null) {
            throw new ModelNotFoundException;
        }

        OwnershipTransferRequestAggregate::retrieve($requestId)
            ->execute($command)
            ->persist();

        // refresh(), not fresh(): it re-reads the projector's writes into THIS
        // instance and is typed non-null, where fresh() hands back a nullable.
        return $transfer->refresh();
    }
}
