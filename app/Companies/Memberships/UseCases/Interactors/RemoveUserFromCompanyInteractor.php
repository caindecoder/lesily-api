<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use App\Companies\Memberships\Exceptions\SelfRemovalBlockedException;
use App\Companies\Memberships\Exceptions\OwnerRemovalBlockedException;
use App\Companies\Memberships\UseCases\Commands\RemoveUserFromCompanyCommand;

/**
 * Remove a member from the company (UC-1-024). Guards live here at the
 * interactor seam (the aggregate is guardless — cd-users §5): the company-scoped
 * lookup hides unknown/cross-tenant/removed ids as 404.
 */
class RemoveUserFromCompanyInteractor
{
    public function execute(Company $company, RemoveUserFromCompanyCommand $command, string $membershipId): void
    {
        $membership = CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->whereKey($membershipId)
            ->firstOrFail();

        if ($membership->user_id === $command->removedBy) {
            throw new SelfRemovalBlockedException;
        }

        if ($membership->role()->where('code', 'owner')->exists()) {
            throw new OwnerRemovalBlockedException;
        }
    }
}
