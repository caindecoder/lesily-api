<?php

namespace App\Companies\UseCases\Interactors;

use RuntimeException;
use App\Companies\Entities\OwnershipTransferCase;
use App\Companies\UseCases\Commands\VerifyOwnershipTransferCaseCommand;

class VerifyOwnershipTransferCaseInteractor
{
    public function execute(VerifyOwnershipTransferCaseCommand $command): OwnershipTransferCase
    {
        $case = OwnershipTransferCase::query()->findOrFail($command->caseId);

        throw new RuntimeException('Not implemented');
    }
}
