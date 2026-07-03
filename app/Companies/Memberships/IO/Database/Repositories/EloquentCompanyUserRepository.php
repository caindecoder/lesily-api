<?php

namespace App\Companies\Memberships\IO\Database\Repositories;

use Illuminate\Support\Collection;
use App\Companies\Entities\CompanyUser;
use App\Companies\Memberships\UseCases\Commands\MembershipFilters;
use App\Companies\Memberships\UseCases\Repositories\CompanyUserRepositoryInterface;

class EloquentCompanyUserRepository implements CompanyUserRepositoryInterface
{
    /**
     * @return Collection<int, CompanyUser>
     */
    public function listForCompany(string $companyId, MembershipFilters $filters): Collection
    {
        return CompanyUser::query()
            ->where('company_id', $companyId)
            ->with('user')
            ->get()
            ->toBase();
    }
}
