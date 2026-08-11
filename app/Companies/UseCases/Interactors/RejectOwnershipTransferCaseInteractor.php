<?php

namespace App\Companies\UseCases\Interactors;

use App\Models\User;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Companies\Enums\OwnershipTransferCaseStatus;
use App\Companies\UseCases\Commands\RejectOwnershipTransferCaseCommand;
use App\Companies\Exceptions\OwnershipTransferCaseAlreadyClosedException;

/**
 * A platform admin rejects an ownership-transfer case (UC-1-128; slice-11R
 * spec §5): a plain write that closes the case with its reason on the record
 * and never touches company or membership state.
 */
class RejectOwnershipTransferCaseInteractor
{
    public function execute(RejectOwnershipTransferCaseCommand $command, User $admin, AdminRequestContext $context): OwnershipTransferCase
    {
        $case = OwnershipTransferCase::query()->findOrFail($command->caseId);

        if ($case->status === OwnershipTransferCaseStatus::Closed) {
            throw new OwnershipTransferCaseAlreadyClosedException;
        }

        return $case;
    }
}
