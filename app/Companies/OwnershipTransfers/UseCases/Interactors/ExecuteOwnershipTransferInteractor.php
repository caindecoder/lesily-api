<?php

namespace App\Companies\OwnershipTransfers\UseCases\Interactors;

use RuntimeException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;

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

    public function execute(string $requestId): OwnershipTransferRequest
    {
        $transfer = $this->transfers->findByIdAcrossTenants($requestId);

        if ($transfer === null) {
            throw new ModelNotFoundException;
        }

        throw new RuntimeException('Not implemented');
    }
}
