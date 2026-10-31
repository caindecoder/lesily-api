<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use Illuminate\Database\QueryException;
use Illuminate\Database\DeadlockException;
use App\Foundation\Database\LockContention;
use App\Foundation\Auth\UseCases\TokenRevoker;
use App\Companies\Memberships\Aggregates\UserMembership;
use App\Companies\Memberships\Exceptions\SelfRemovalBlockedException;
use App\Companies\Memberships\Exceptions\OwnerRemovalBlockedException;
use App\Companies\Memberships\Exceptions\MembershipContentionException;
use App\Companies\Memberships\UseCases\Commands\RemoveUserFromCompanyCommand;

/**
 * Remove a member from the company (UC-1-024). Guards live here at the
 * interactor seam (the aggregate is guardless — cd-users §5): the company-scoped
 * lookup hides unknown/cross-tenant/removed ids as 404. The lookup locks the
 * pivot row inside the transaction so the owner-guard decides against live
 * state, not a snapshot a concurrent owner-writer can invalidate (OQ-1-158).
 */
class RemoveUserFromCompanyInteractor
{
    public function __construct(private TokenRevoker $tokenRevoker) {}

    public function execute(Company $company, RemoveUserFromCompanyCommand $command, string $membershipId): void
    {
        try {
            $this->executeLocked($company, $command, $membershipId);
        } catch (DeadlockException $exception) {
            throw new MembershipContentionException($exception);
        } catch (QueryException $exception) {
            throw LockContention::caused($exception)
                ? new MembershipContentionException($exception)
                : $exception;
        }
    }

    protected function executeLocked(Company $company, RemoveUserFromCompanyCommand $command, string $membershipId): void
    {
        DB::transaction(function () use ($company, $command, $membershipId): void {
            $membership = CompanyUser::query()
                ->where('company_id', $company->getKey())
                ->whereKey($membershipId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($membership->user_id === $command->removedBy->getKey()) {
                throw new SelfRemovalBlockedException;
            }

            if ($membership->role()->where('code', 'owner')->exists()) {
                throw new OwnerRemovalBlockedException;
            }

            UserMembership::retrieve($membership->getKey())
                ->remove($command)
                ->persist();

            // D6 coarse account-level session kill (inline in the tx): revoking
            // ALL the removed member's tokens logs them out everywhere — the
            // company-scoped token refinement is a declared future fix.
            $this->tokenRevoker->revokeAllForUserExcept($membership->user()->sole(), null);
        });
    }
}
