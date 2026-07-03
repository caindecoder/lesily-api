<?php

namespace App\Companies\Memberships\IO\Database\Repositories;

use App\Models\User;
use App\Companies\Entities\CompanyUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Companies\Memberships\UseCases\Commands\MembershipFilters;
use App\Companies\Memberships\UseCases\Repositories\CompanyUserRepositoryInterface;

class EloquentCompanyUserRepository implements CompanyUserRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, CompanyUser>
     */
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
            ->when(
                $filters->search !== null,
                function ($query) use ($filters) {
                    // LIKE metacharacters in user input are inert — a literal `_`/`%`
                    // search must not wildcard-over-match (MySQL's default escape is \).
                    $term = addcslashes((string) $filters->search, '%_\\');

                    return $query->whereHas('user', fn ($user) => $user
                        ->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%"));
                }
            )
            ->with(['user', 'role'])
            ->when(
                // Newest membership first (?sort=-created_at, api-1-users).
                $filters->sort === '-created_at',
                fn ($query) => $query->orderByDesc('created_at'),
                // Default (and explicit sort=name): member name ascending. Subquery
                // over a join keeps the SoftDeletes scope + select untouched.
                fn ($query) => $query->orderBy(
                    User::query()->select('name')->whereColumn('users.id', 'company_user.user_id')
                )
            )
            ->paginate(perPage: $filters->per_page ?? 15, page: $filters->page ?? 1);
    }
}
