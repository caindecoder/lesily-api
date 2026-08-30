<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use Illuminate\Database\QueryException;
use Illuminate\Database\DeadlockException;
use App\Foundation\Database\LockContention;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Companies\Memberships\Aggregates\UserMembership;
use App\Companies\Memberships\Exceptions\MembershipContentionException;
use App\Companies\Memberships\Exceptions\OwnerTransferRequiredException;
use App\Companies\Memberships\Exceptions\SelfRoleChangeBlockedException;
use App\Companies\Memberships\UseCases\Commands\ChangeMembershipRoleCommand;

/**
 * Change a membership's role (UC-1-023). Guards live here at the interactor
 * seam (the aggregate is guardless — cd-users §5, s112 rulings): the
 * company-scoped lookup hides unknown/cross-tenant/removed ids as 404, the
 * self and owner-target checks reject with their 422 codes, and the role-code
 * whitelist already ran at the command boundary (R-D). The lookup locks the
 * pivot row inside the transaction so the owner-guard decides against live
 * state, not a snapshot a concurrent owner-writer can invalidate (OQ-1-158).
 */
class ChangeMembershipRoleInteractor
{
    public function execute(Company $company, ChangeMembershipRoleCommand $command, string $membershipId): CompanyUser
    {
        try {
            return $this->executeLocked($company, $command, $membershipId);
        } catch (DeadlockException $exception) {
            throw new MembershipContentionException($exception);
        } catch (QueryException $exception) {
            throw LockContention::caused($exception)
                ? new MembershipContentionException($exception)
                : $exception;
        }
    }

    private function executeLocked(Company $company, ChangeMembershipRoleCommand $command, string $membershipId): CompanyUser
    {
        return DB::transaction(function () use ($company, $command, $membershipId): CompanyUser {
            $membership = CompanyUser::query()
                ->where('company_id', $company->getKey())
                ->whereKey($membershipId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($membership->user_id === $command->changedBy) {
                throw new SelfRoleChangeBlockedException;
            }

            if ($membership->role()->where('code', 'owner')->exists()) {
                throw new OwnerTransferRequiredException;
            }

            $toRole = Role::query()->where('code', $command->roleCode)->sole();
            /** @var string $toRoleId roles are UUID-keyed (HasUuids) */
            $toRoleId = $toRole->getKey();

            UserMembership::retrieve($membership->getKey())
                ->changeRole($command, $membership->role_id, $toRoleId)
                ->persist();

            return $membership;
        });
    }
}
