<?php

namespace App\Companies\OwnershipTransfers\UseCases\Interactors;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\UseCases\Commands\VerifyOwnershipTransferIdentityCommand;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;
use App\Companies\OwnershipTransfers\Aggregates\OwnershipTransferRequest as OwnershipTransferRequestAggregate;

/**
 * SM-1-05 T2 (UC-1-113) — the platform admin confirms the requester's identity
 * and the transfer moves Requested → Verified.
 *
 * The cross-entity guards live HERE rather than on the aggregate: the aggregate
 * never reads the database, so a fact owned by another aggregate (the parent
 * company's legal hold) can only be checked at this layer (E07 §5; the LD
 * ApproveLeaveAction shape, where the billing-cancelled refusal sits in the
 * Action and only the state guard sits on the aggregate). The aggregate keeps
 * the one invariant it CAN see on its own stream: the from-Requested guard.
 */
class VerifyOwnershipTransferIdentityInteractor
{
    public function __construct(private readonly OwnershipTransferRequestRepositoryInterface $transfers) {}

    public function execute(string $requestId, VerifyOwnershipTransferIdentityCommand $command): OwnershipTransferRequest
    {
        $transfer = $this->transfers->findByIdAcrossTenants($requestId);

        if ($transfer === null) {
            throw new ModelNotFoundException;
        }

        OwnershipTransferRequestAggregate::retrieve($requestId)
            ->verifyIdentity($command)
            ->persist();

        return $transfer->fresh();
    }
}
