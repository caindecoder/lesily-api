<?php

namespace App\Companies\UseCases\Interactors;

use RuntimeException;
use App\Companies\Entities\CompanyUser;
use App\Companies\Entities\OwnershipTransferCase;
use App\Companies\Exceptions\TargetNotMemberException;
use App\Companies\UseCases\Commands\VerifyOwnershipTransferCaseCommand;

class VerifyOwnershipTransferCaseInteractor
{
    public function execute(VerifyOwnershipTransferCaseCommand $command): OwnershipTransferCase
    {
        $case = OwnershipTransferCase::query()->findOrFail($command->caseId);

        $this->guardTargetIsLiveMember($case, $command->newOwnerUserId);

        throw new RuntimeException('Not implemented');
    }

    /**
     * The incoming owner must hold a LIVE membership of the case's company
     * (UC-1-113 error flow 3c) — decided against the case row, never the
     * payload, so a membership elsewhere can never satisfy it.
     */
    private function guardTargetIsLiveMember(OwnershipTransferCase $case, string $newOwnerUserId): void
    {
        $membership = CompanyUser::query()
            ->where('company_id', $case->company_id)
            ->where('user_id', $newOwnerUserId)
            ->first();

        if ($membership === null) {
            throw new TargetNotMemberException;
        }
    }
}
