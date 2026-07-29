<?php

namespace App\Companies\OwnershipTransfers\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Companies\Entities\CompanyUser;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Companies\OwnershipTransfers\UseCases\Values\OwnershipSwap;
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
