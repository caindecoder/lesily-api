<?php

namespace App\Companies\OwnershipTransfers\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\UseCases\Values\OwnershipSwap;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\Exceptions\OwnershipTransferOwnerChangedException;
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
 *
 * The cross-entity work lives HERE and cannot live on the aggregate, which
 * never reads the database (E07 §5; the LD ApproveLeaveAction shape). That
 * covers both the resolution of the swap and the live re-validation guards
 * ruling P3 requires — the owner and the target could each have moved between
 * T1 and now, and the aggregate can see neither.
 *
 * Pattern A: the explicit DB::transaction() wraps the whole projector pipeline,
 * so the four read-model writes and the stored event commit together or not at
 * all (R-043; SD-1-02 §2.2).
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

        $this->guardOwnerHasNotChanged($transfer);

        $swap = $this->resolveSwap($transfer);

        DB::transaction(function () use ($requestId, $command, $swap): void {
            OwnershipTransferRequestAggregate::retrieve($requestId)
                ->execute($command, $swap)
                ->persist();
        });

        // refresh(), not fresh(): it re-reads the projectors' writes into THIS
        // instance and is typed non-null, where fresh() hands back a nullable.
        return $transfer->refresh();
    }

    /**
     * The transfer recorded who the owner was at T1; nothing revalidates it
     * until now (UC-1-093 error flow 3b). A prior transfer could have moved
     * ownership while this one sat verified, and executing then would demote
     * somebody who is no longer the owner and promote against a stale premise.
     *
     * firstOrFail, not value(): a missing company would make value() return null
     * and the guard fail OPEN — the same trap the legal-hold guard documents.
     *
     * SCOPED to the open window deliberately. This guard re-validates a PREMISE
     * of a pending transition; it has nothing to say about a transfer that can no
     * longer transition at all, and the aggregate owns that question. Unscoped it
     * pre-empted the aggregate: an already-executed transfer reported "the owner
     * has changed", which is true only because executing it is what changed the
     * owner — a strictly less useful answer than "already executed", and one that
     * would have sent an admin looking for a transfer that never happened.
     *
     * Reading `status` from the projection here is safe in a way the expiry
     * scan's read was not (ruling O1): it decides only whether to run an EXTRA
     * guard, never whether to write. The aggregate remains the sole authority on
     * whether the transition may happen.
     */
    private function guardOwnerHasNotChanged(OwnershipTransferRequest $transfer): void
    {
        if ($transfer->status !== OwnershipTransferStatus::Verified) {
            return;
        }

        $liveOwnerId = Company::query()->whereKey($transfer->company_id)->firstOrFail()->owner_id;

        if ($liveOwnerId !== $transfer->current_owner_user_id) {
            throw new OwnershipTransferOwnerChangedException;
        }
    }

    /**
     * Resolve the swap from LIVE state. The prior role ids are captured here,
     * before any projector runs, because they are unrecoverable afterwards:
     * MembershipProjector overwrites company_user.role_id in place, so a
     * projector trying to read them later would see the post-transfer values.
     */
    private function resolveSwap(OwnershipTransferRequest $transfer): OwnershipSwap
    {
        $outgoing = $this->liveMembership($transfer->company_id, $transfer->current_owner_user_id);
        $incoming = $this->liveMembership($transfer->company_id, $transfer->target_user_id);

        return new OwnershipSwap(
            companyId: $transfer->company_id,
            oldOwnerUserId: $transfer->current_owner_user_id,
            newOwnerUserId: $transfer->target_user_id,
            oldOwnerMembershipId: $outgoing->getKey(),
            oldOwnerOldRoleId: $outgoing->role_id,
            oldOwnerNewRoleId: $this->roleId('admin'),
            newOwnerMembershipId: $incoming->getKey(),
            newOwnerOldRoleId: $incoming->role_id,
            newOwnerNewRoleId: $this->roleId('owner'),
            verificationNotes: $transfer->verification_notes,
        );
    }

    private function liveMembership(string $companyId, string $userId): CompanyUser
    {
        return CompanyUser::query()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->firstOrFail();
    }

    private function roleId(string $code): string
    {
        return Role::query()->where('code', $code)->sole()->getKey();
    }
}
