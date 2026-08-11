<?php

namespace App\Companies\UseCases\Interactors;

use App\Models\User;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Companies\UseCases\Commands\RejectOwnershipTransferCaseCommand;

/**
 * A platform admin rejects an ownership-transfer case (UC-1-128; slice-11R
 * spec §5): a plain write that closes the case with its reason on the record
 * and never touches company or membership state.
 */
class RejectOwnershipTransferCaseInteractor
{
    public function execute(RejectOwnershipTransferCaseCommand $command, User $admin, AdminRequestContext $context): OwnershipTransferCase
    {
        return OwnershipTransferCase::query()->findOrFail($command->caseId);
    }
}
