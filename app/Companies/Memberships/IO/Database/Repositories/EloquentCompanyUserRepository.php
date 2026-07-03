<?php

namespace App\Companies\Memberships\IO\Database\Repositories;

use App\Models\User;
use App\Companies\Entities\CompanyUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Companies\Memberships\UseCases\Commands\MembershipFilters;
use App\Companies\Memberships\UseCases\Repositories\CompanyUserRepositoryInterface;

class EloquentCompanyUserRepository implements CompanyUserRepositoryInterface
{
    public function listForCompany(string $companyId, MembershipFilters $filters): LengthAwarePaginator
    {
        return CompanyUser::query()
            ->where('company_id', $companyId)
            ->with(['user', 'role'])
            ->orderBy(
                // Default sort: member name ascending (api-1-users). Subquery over a
                // join keeps the SoftDeletes scope + select untouched (§2 advanced-queries).
                User::query()->select('name')->whereColumn('users.id', 'company_user.user_id')
            )
            ->paginate(perPage: $filters->per_page ?? 15, page: $filters->page ?? 1);
    }
}
