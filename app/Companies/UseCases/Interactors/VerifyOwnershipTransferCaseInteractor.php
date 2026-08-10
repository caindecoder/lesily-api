<?php

namespace App\Companies\UseCases\Interactors;

use RuntimeException;
use App\Companies\Entities\OwnershipTransferCase;

class VerifyOwnershipTransferCaseInteractor
{
    public function execute(string $caseId): OwnershipTransferCase
    {
        $case = OwnershipTransferCase::query()->findOrFail($caseId);

        throw new RuntimeException('Not implemented');
    }
}
