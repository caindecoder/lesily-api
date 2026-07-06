<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;

/**
 * Remove a member from the company (UC-1-024). Guards live here at the
 * interactor seam (the aggregate is guardless — cd-users §5): the company-scoped
 * lookup hides unknown/cross-tenant/removed ids as 404.
 */
class RemoveUserFromCompanyInteractor
{
    public function execute(Company $company, string $membershipId): void
    {
        CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->whereKey($membershipId)
            ->firstOrFail();
    }
}
