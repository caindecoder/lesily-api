<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use App\Companies\Memberships\Exceptions\OwnerTransferRequiredException;

/**
 * Change a membership's role (UC-1-023). Guards live here at the interactor
 * seam (the aggregate is guardless — cd-users §5, s112 rulings).
 */
class ChangeMembershipRoleInteractor
{
    public function execute(Company $company, string $membershipId): CompanyUser
    {
        throw new OwnerTransferRequiredException;
    }
}
