<?php

namespace App\Companies\Memberships\IO\Database\Repositories;

use stdClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Companies\Memberships\UseCases\Commands\MembershipFilters;
use App\Companies\Memberships\UseCases\Repositories\CompanyUserRepositoryInterface;

/**
 * The roster reader (UC-1-017): a UNION ALL of the company's ACTIVE memberships
 * and its LIVE-PENDING invitations (the Task-4 union — s110 R4a/R4b).
 * Query-builder, not Eloquent: the two arms align on one aliased column shape
 * and sort on the user's name with the invitation email as its fallback
 * (`sort_name` — s110 sort ruling), so invited rows interleave alphabetically.
 * Filters span both arms uniformly (s110 filters ruling); SQL stays below this
 * boundary (E11).
 */
class EloquentCompanyUserRepository implements CompanyUserRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function listForCompany(string $companyId, MembershipFilters $filters): LengthAwarePaginator
    {
        $rows = match ($filters->status) {
            'active' => $this->activeMembers($companyId, $filters),
            'invited' => $this->pendingInvitations($companyId, $filters),
            default => $this->activeMembers($companyId, $filters)
                ->unionAll($this->pendingInvitations($companyId, $filters)),
        };

        return $rows
            ->when(
                // Newest membership/invitation first (?sort=-created_at, api-1-users).
                $filters->sort === '-created_at',
                fn (Builder $query) => $query->orderByDesc('created_at'),
                fn (Builder $query) => $query->orderBy('sort_name'),
            )
            // Stable tie-breaker: equal sort keys must not shuffle rows between
            // page requests of a paginated union.
            ->orderBy('id')
            ->paginate(perPage: $filters->per_page ?? 15, page: $filters->page ?? 1);
    }

    public function findById(string $companyId, string $membershipId): ?stdClass
    {
        /** @var stdClass|null $row */
        $row = $this->activeMembers($companyId, MembershipFilters::validateAndCreate([]))
            ->where('company_user.id', $membershipId)
            ->first();

        return $row;
    }

    private function activeMembers(string $companyId, MembershipFilters $filters): Builder
    {
        return DB::table('company_user')
            ->join('users', 'users.id', '=', 'company_user.user_id')
            ->join('roles', 'roles.id', '=', 'company_user.role_id')
            ->where('company_user.company_id', $companyId)
            // DB::table sees no Eloquent SoftDeletes scope — removed members are
            // excluded here explicitly (UC-1-024 D5).
            ->whereNull('company_user.deleted_at')
            ->when($filters->role !== null, fn (Builder $query) => $query->where('roles.code', $filters->role))
            ->when($filters->search !== null, function (Builder $query) use ($filters): Builder {
                $term = $this->literalLikeTerm((string) $filters->search);

                return $query->where(fn (Builder $match) => $match
                    ->where('users.name', 'like', "%{$term}%")
                    ->orWhere('users.email', 'like', "%{$term}%"));
            })
            ->select([
                'company_user.id as id',
                'users.id as user_id',
                'users.name as user_name',
                'users.email as user_email',
                'roles.code as role_code',
                'roles.name as role_name',
                DB::raw("'active' as status"),
                DB::raw('null as invited_at'),
                DB::raw('null as last_active_at'),
                'users.name as sort_name',
                'company_user.created_at as created_at',
            ]);
    }

    private function pendingInvitations(string $companyId, MembershipFilters $filters): Builder
    {
        return DB::table('invitations')
            ->join('roles', 'roles.id', '=', 'invitations.role_id')
            ->where('invitations.company_id', $companyId)
            // Live pending only (R1 s110 + R6 s111): unaccepted + not-revoked +
            // unexpired — an accepted, revoked, or lapsed invitation never rides
            // the roster.
            ->whereNull('invitations.accepted_at')
            ->whereNull('invitations.revoked_at')
            ->where('invitations.expires_at', '>', now())
            ->when($filters->role !== null, fn (Builder $query) => $query->where('roles.code', $filters->role))
            ->when($filters->search !== null, function (Builder $query) use ($filters): Builder {
                $term = $this->literalLikeTerm((string) $filters->search);

                return $query->where('invitations.email', 'like', "%{$term}%");
            })
            ->select([
                'invitations.id as id',
                DB::raw('null as user_id'),
                DB::raw('null as user_name'),
                'invitations.email as user_email',
                'roles.code as role_code',
                'roles.name as role_name',
                DB::raw("'invited' as status"),
                'invitations.created_at as invited_at',
                DB::raw('null as last_active_at'),
                'invitations.email as sort_name',
                'invitations.created_at as created_at',
            ]);
    }

    /**
     * LIKE metacharacters in user input are inert — a literal `_`/`%` search
     * must not wildcard-over-match (MySQL's default escape is \).
     */
    private function literalLikeTerm(string $search): string
    {
        return addcslashes($search, '%_\\');
    }
}
