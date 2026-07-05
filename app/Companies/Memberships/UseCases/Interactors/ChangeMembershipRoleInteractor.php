<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use App\Companies\Memberships\Exceptions\OwnerTransferRequiredException;
use App\Companies\Memberships\Exceptions\SelfRoleChangeBlockedException;
use App\Companies\Memberships\UseCases\Commands\ChangeMembershipRoleCommand;

/**
 * Change a membership's role (UC-1-023). Guards live here at the interactor
 * seam (the aggregate is guardless — cd-users §5, s112 rulings).
 */
class ChangeMembershipRoleInteractor
{
    public function execute(Company $company, ChangeMembershipRoleCommand $command, string $membershipId): CompanyUser
    {
        $membership = CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->whereKey($membershipId)
            ->firstOrFail();

        if ($membership->user_id === $command->changedBy) {
            throw new SelfRoleChangeBlockedException;
        }

        throw new OwnerTransferRequiredException;
    }
}
