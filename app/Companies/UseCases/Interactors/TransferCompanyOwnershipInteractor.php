<?php

namespace App\Companies\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use Illuminate\Database\QueryException;
use Illuminate\Database\DeadlockException;
use App\Companies\Exceptions\StaleOwnershipException;
use App\Companies\Exceptions\TargetNotMemberException;
use App\Companies\Aggregates\Company as CompanyAggregate;
use App\Companies\Exceptions\SelfTransferBlockedException;
use App\Companies\Exceptions\OwnershipTransferContentionException;
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
    private const int MYSQL_LOCK_WAIT_TIMEOUT = 1205;

    private const int MYSQL_DEADLOCK = 1213;

    public function execute(Company $company, TransferCompanyOwnershipCommand $command): CompanyUser
    {
        if ($command->newOwnerUserId === $command->transferredBy) {
            throw new SelfTransferBlockedException;
        }

        try {
            return $this->executeLocked($company, $command);
        } catch (DeadlockException) {
            // Laravel wraps a concurrency loser inside a nested transaction;
            // at top level the raw QueryException surfaces instead (below).
            throw new OwnershipTransferContentionException;
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[1] ?? null, [self::MYSQL_LOCK_WAIT_TIMEOUT, self::MYSQL_DEADLOCK], true)) {
                throw new OwnershipTransferContentionException;
            }

            throw $exception;
        }
    }

    private function executeLocked(Company $company, TransferCompanyOwnershipCommand $command): CompanyUser
    {
        return DB::transaction(function () use ($company, $command): CompanyUser {
            $lockedCompany = Company::query()
                ->whereKey($company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCompany->owner_id !== $command->transferredBy) {
                throw new StaleOwnershipException;
            }

            $memberships = $this->lockedMemberships($company, [$lockedCompany->owner_id, $command->newOwnerUserId]);
            $target = $memberships[$command->newOwnerUserId] ?? null;

            if ($target === null) {
                throw new TargetNotMemberException;
            }

            CompanyAggregate::retrieve($company->getKey())
                ->transferOwnership($command, $lockedCompany->owner_id)
                ->persist();

            return $target->refresh();
        });
    }

    /**
     * Lock the pivot rows in ascending user-id order — both writers of an
     * opposite-direction transfer pair order the same two rows identically, so
     * they serialize instead of deadlocking (the s169 lock-order rule).
     *
     * @param  array<int, string>  $userIds
     * @return array<string, CompanyUser> keyed by user_id
     */
    private function lockedMemberships(Company $company, array $userIds): array
    {
        $memberships = [];

        foreach (collect($userIds)->sort()->values() as $userId) {
            $membership = CompanyUser::query()
                ->where('company_id', $company->getKey())
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($membership !== null) {
                $memberships[$userId] = $membership;
            }
        }

        return $memberships;
    }
}
