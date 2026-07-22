<?php

namespace App\Companies\OwnershipTransfers\UseCases\Interactors;

use App\Models\User;
use RuntimeException;
use App\Companies\Entities\Company;
use App\Companies\OwnershipTransfers\UseCases\Commands\RequestOwnershipTransferCommand;

/**
 * Request an ownership transfer (UC-1-028, SM-1-05 T1): validate the command,
 * guard the target's eligibility, and record OwnershipTransferRequested for
 * the platform-support verification queue.
 */
class RequestOwnershipTransferInteractor
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(Company $company, User $actor, array $input): never
    {
        RequestOwnershipTransferCommand::validateAndCreate([
            ...$input,
            'companyId' => $company->getKey(),
            'currentOwnerUserId' => $actor->getKey(),
        ]);

        throw new RuntimeException('Not implemented');
    }
}
