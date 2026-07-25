<?php

namespace App\Companies\OwnershipTransfers\UseCases\Interactors;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\UseCases\Commands\RejectOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;

/**
 * SM-1-05 T3 + T5 (UC-1-128) — the platform admin ends the transfer with a
 * reasoned rejection, from Requested (pre-verification) or Verified
 * (execution-time). The aggregate picks the window from its own state.
 *
 * Deliberately NO parent-company read, unlike its verify sibling: §S10b's legal
 * hold freezes ownership CHANGES, and a rejection changes no ownership and
 * touches no company_user pivot row, so a held company's transfer can still be
 * closed out (s151 ruling H4). Blocking it would strand held tenants with
 * un-closable requests, since the expiry scan skips them as well (slice D3).
 */
class RejectOwnershipTransferInteractor
{
    public function __construct(private readonly OwnershipTransferRequestRepositoryInterface $transfers) {}

    public function execute(string $requestId, RejectOwnershipTransferCommand $command): OwnershipTransferRequest
    {
        $transfer = $this->transfers->findByIdAcrossTenants($requestId);

        if ($transfer === null) {
            throw new ModelNotFoundException;
        }

        return $transfer;
    }
}
