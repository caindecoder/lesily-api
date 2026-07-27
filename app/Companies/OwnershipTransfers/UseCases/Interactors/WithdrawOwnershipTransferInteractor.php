<?php

namespace App\Companies\OwnershipTransfers\UseCases\Interactors;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\Exceptions\NotTheTransferRequesterException;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;

/**
 * SM-1-05 T6 + T7 (UC-1-114 / UC-1-115) — the original requester withdraws their
 * own transfer, from `Requested` (pre-verification) or `Verified`
 * (post-verification). The aggregate picks the window from its own state.
 *
 * The find is COMPANY-SCOPED, unlike the admin transitions' unscoped
 * `findByIdAcrossTenants`: this is a tenant surface, so another tenant's id must
 * be indistinguishable from an absent one (404 either way).
 */
class WithdrawOwnershipTransferInteractor
{
    public function __construct(private readonly OwnershipTransferRequestRepositoryInterface $transfers) {}

    public function execute(string $companyId, string $requestId, string $actorId): OwnershipTransferRequest
    {
        $transfer = $this->transfers->findByIdForCompany($companyId, $requestId);

        if ($transfer === null) {
            throw new ModelNotFoundException;
        }

        // Withdrawal is the REQUESTER's authority, not the owner role's: the
        // designated target cannot end a transfer, and neither can an owner who
        // did not raise this one. Checked here rather than in the aggregate
        // because it is answerable from the projection alone and needs no
        // stream replay — the mirror of s149 B1, which put legal_hold in the
        // interactor for the opposite reason (it is another aggregate's state).
        if ($transfer->current_owner_user_id !== $actorId) {
            throw new NotTheTransferRequesterException;
        }

        return $transfer;
    }
}
