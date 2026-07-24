<?php

namespace App\Companies\OwnershipTransfers\UseCases\Interactors;

use App\Companies\Entities\Company;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Companies\Exceptions\CompanyUnderLegalHoldException;
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

        $this->guardCompanyIsNotUnderLegalHold($transfer);

        OwnershipTransferRequestAggregate::retrieve($requestId)
            ->verifyIdentity($command)
            ->persist();

        return $transfer->fresh();
    }

    /**
     * §S10b: a hold freezes ownership changes, not merely the expiry timers
     * (slice-#11 D3). Runs BEFORE the aggregate is retrieved so a held transfer
     * never records a T2 event — and reads the flag as a scalar rather than
     * hydrating the whole Company, since a guard needs one boolean.
     */
    private function guardCompanyIsNotUnderLegalHold(OwnershipTransferRequest $transfer): void
    {
        $isHeld = Company::query()->whereKey($transfer->company_id)->value('legal_hold');

        if ((bool) $isHeld) {
            throw new CompanyUnderLegalHoldException;
        }
    }
}
