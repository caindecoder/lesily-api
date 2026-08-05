<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Companies\Memberships\Aggregates\UserMembership;
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

            UserMembership::retrieve($membership->getKey())
                ->changeRole($command, (string) $membership->role_id, (string) $toRole->getKey())
                ->persist();

            return $membership;
        });
    }
}
