<?php

namespace App\Companies\OwnershipTransfers\Aggregates;

use Carbon\CarbonImmutable;
use App\Foundation\EventStore\AggregateRoot;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRequested;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferIdentityVerified;
use App\Companies\OwnershipTransfers\Exceptions\OpenOwnershipTransferExistsException;
use App\Companies\OwnershipTransfers\UseCases\Commands\RequestOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\Exceptions\OwnershipTransferNotVerifiableException;
use App\Companies\OwnershipTransfers\UseCases\Commands\VerifyOwnershipTransferIdentityCommand;

/**
 * The transfer-request lifecycle stream (SM-1-05): one aggregate per request,
 * multi-actor (Owner requests → support verifies → platform admin executes →
 * system expires). Unlike the guardless Memberships methods, transitions here
 * carry aggregate state guards (E28) — later slice tasks add
 * verifyIdentity / execute / reject / withdraw / expireIfStale.
 */
class OwnershipTransferRequest extends AggregateRoot
{
    private ?OwnershipTransferStatus $status = null;

    /**
     * SM-1-05 T1 genesis. The re-fire guard is Tier-2 of the one-open-request
     * invariant — the interactor's projection read (Tier-1) covers the
     * cross-stream case; this guard keeps a replayed or duplicated stream from
     * recording a second genesis.
     */
    public function request(RequestOwnershipTransferCommand $command): static
    {
        if ($this->status !== null) {
            throw new OpenOwnershipTransferExistsException;
        }

        return $this->forCompany($command->companyId)
            ->recordThat(new OwnershipTransferRequested(
                requestId: $this->uuid(),
                companyId: $command->companyId,
                currentOwnerUserId: $command->currentOwnerUserId,
                targetUserId: $command->targetUserId,
                reason: $command->reason,
                requestChannel: $command->requestChannel,
                requestedAt: CarbonImmutable::now(),
            ));
    }

    public function applyOwnershipTransferRequested(OwnershipTransferRequested $event): void
    {
        $this->status = OwnershipTransferStatus::Requested;
    }

    /**
     * SM-1-05 T2. The ONE invariant this aggregate can see on its own stream:
     * only a Requested transfer can be verified, so a re-verify and every
     * terminal-state attempt are refused here (E28 — enumerate the transitions,
     * refuse the rest). The parent company's legal hold is NOT checked here: it
     * belongs to another aggregate and this one never reads the database, so the
     * interactor guards it (LD ApproveLeaveAction holds its cross-entity guard
     * the same way).
     */
    public function verifyIdentity(VerifyOwnershipTransferIdentityCommand $command): static
    {
        if ($this->status !== OwnershipTransferStatus::Requested) {
            throw new OwnershipTransferNotVerifiableException;
        }

        return $this->recordThat(new OwnershipTransferIdentityVerified(
            requestId: $this->uuid(),
            verifiedBy: $command->verifiedBy,
            verificationNotes: $command->verificationNotes,
            verificationArtefacts: $command->verificationArtefacts,
            verifiedAt: CarbonImmutable::now(),
        ));
    }

    public function applyOwnershipTransferIdentityVerified(OwnershipTransferIdentityVerified $event): void
    {
        $this->status = OwnershipTransferStatus::Verified;
    }
}
