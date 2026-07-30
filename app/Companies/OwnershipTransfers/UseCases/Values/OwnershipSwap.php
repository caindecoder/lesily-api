<?php

namespace App\Companies\OwnershipTransfers\UseCases\Values;

/**
 * The resolved shape of a T4 swap: who moves, which pivot rows move, and which
 * roles they move between.
 *
 * Separate from ExecuteOwnershipTransferCommand deliberately. The Command is the
 * CLIENT contract and ruling P3 keeps it at `reason` alone — execute
 * re-validates, it does not re-enter. Everything here is resolved by the
 * interactor from live state immediately before the aggregate call, which is
 * also why it cannot live on the Command: none of it is the caller's to supply.
 *
 * It exists as a value object rather than nine more parameters because a
 * parameter object is the sanctioned answer to that (the arg-count rule's
 * value-object carve-out).
 */
class OwnershipSwap
{
    public function __construct(
        public string $companyId,
        public string $oldOwnerUserId,
        public string $newOwnerUserId,
        public string $oldOwnerMembershipId,
        public string $oldOwnerOldRoleId,
        public string $oldOwnerNewRoleId,
        public string $newOwnerMembershipId,
        public string $newOwnerOldRoleId,
        public string $newOwnerNewRoleId,
    ) {}
}
