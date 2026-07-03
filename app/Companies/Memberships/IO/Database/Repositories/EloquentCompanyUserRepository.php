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
            ->when(
                $filters->role !== null,
                fn ($query) => $query->whereRelation('role', 'code', $filters->role)
            )
            ->when(
                // Every pivot row derives status 'active' this increment, so the
                // invited filter matches nothing by construction — pending
                // invitations join the roster at Task 4 (slice §7, declared s108).
                $filters->status === 'invited',
                fn ($query) => $query->whereRaw('0 = 1')
            )
            ->with(['user', 'role'])
            ->orderBy(
                // Default sort: member name ascending (api-1-users). Subquery over a
                // join keeps the SoftDeletes scope + select untouched (§2 advanced-queries).
                User::query()->select('name')->whereColumn('users.id', 'company_user.user_id')
            )
            ->paginate(perPage: $filters->per_page ?? 15, page: $filters->page ?? 1);
    }
}
