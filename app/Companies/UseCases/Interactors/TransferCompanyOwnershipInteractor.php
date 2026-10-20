<?php

namespace App\Companies\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use Illuminate\Database\QueryException;
use Illuminate\Database\DeadlockException;
use App\Foundation\Database\LockContention;
use App\Companies\Exceptions\StaleOwnershipException;
use App\Companies\Exceptions\TargetNotMemberException;
use App\Companies\Aggregates\Company as CompanyAggregate;
use App\Companies\Exceptions\SelfTransferBlockedException;
use App\Companies\Exceptions\OwnershipTransferContentionException;
use App\Companies\UseCases\Commands\TransferCompanyOwnershipCommand;

/**
 * Owns the ownership-transfer transaction (UC-1-028 + the UC-1-093 admin
 * dispatch / spec §4): row locks first, live re-validation under them, then
 * the event-sourced swap. Guards live here at the interactor seam — the
 * aggregate is guardless (T2/cd-users precedent), and the company-scoped
 * locked lookup decides against live state, not a snapshot a concurrent
 * owner-writer can invalidate (OQ-1-158). Both guards compare against the
 * caller-declared EXPECTED owner: the routine path expects its own actor,
 * the admin path expects the owner the case was opened about — never the
 * acting admin.
 */
class TransferCompanyOwnershipInteractor
{
    public function execute(Company $company, TransferCompanyOwnershipCommand $command, string $expectedOwnerUserId): CompanyUser
    {
        if ($command->newOwnerUserId === $expectedOwnerUserId) {
            throw new SelfTransferBlockedException;
        }

        try {
            return $this->executeLocked($company, $command, $expectedOwnerUserId);
        } catch (DeadlockException $exception) {
            throw new OwnershipTransferContentionException($exception);
        } catch (QueryException $exception) {
            throw LockContention::caused($exception)
                ? new OwnershipTransferContentionException($exception)
                : $exception;
        }
    }

    protected function executeLocked(Company $company, TransferCompanyOwnershipCommand $command, string $expectedOwnerUserId): CompanyUser
    {
        return DB::transaction(function () use ($company, $command, $expectedOwnerUserId): CompanyUser {
            $lockedCompany = Company::query()
                ->whereKey($company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCompany->owner_id !== $expectedOwnerUserId) {
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
     * Lock the pivot rows in ascending user-id order (the s169 lock-order
     * rule). Same-company transfers are already serialized by the companies
     * row lock above; the deterministic order removes any reliance on that —
     * insurance for future writers that skip the companies lock.
     *
     * @param  array<int, string>  $userIds
     * @return array<string, CompanyUser> keyed by user_id
     */
    protected function lockedMemberships(Company $company, array $userIds): array
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
