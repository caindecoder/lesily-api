<?php

namespace App\Companies\UseCases\Interactors;

use RuntimeException;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use App\Companies\Exceptions\TargetNotMemberException;
use App\Companies\Exceptions\SelfTransferBlockedException;
use App\Companies\UseCases\Commands\TransferCompanyOwnershipCommand;

/**
 * Owns the ownership-transfer transaction (UC-1-028 / spec §4): row locks
 * first, live re-validation under them, then the event-sourced swap. Guards
 * live here at the interactor seam — the aggregate is guardless (T2/cd-users
 * precedent), and the company-scoped locked lookup decides against live state,
 * not a snapshot a concurrent owner-writer can invalidate (OQ-1-158).
 */
class TransferCompanyOwnershipInteractor
{
    public function execute(Company $company, TransferCompanyOwnershipCommand $command): CompanyUser
    {
        if ($command->newOwnerUserId === $command->transferredBy) {
            throw new SelfTransferBlockedException;
        }

        return DB::transaction(function () use ($company, $command): CompanyUser {
            $target = CompanyUser::query()
                ->where('company_id', $company->getKey())
                ->where('user_id', $command->newOwnerUserId)
                ->lockForUpdate()
                ->first();

            if ($target === null) {
                throw new TargetNotMemberException;
            }

            throw new RuntimeException('Not implemented');
        });
    }
}
