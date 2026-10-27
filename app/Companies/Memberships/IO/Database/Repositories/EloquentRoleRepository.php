<?php

namespace App\Companies\Memberships\IO\Database\Repositories;

use App\Companies\Entities\CompanyUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Companies\Memberships\UseCases\Repositories\RoleRepositoryInterface;

class EloquentRoleRepository implements RoleRepositoryInterface
{
    public function listAssignableForCompany(string $companyId): Collection
    {
        return Role::query()
            ->where('is_assignable', true)
            ->with(['permissions' => fn (BelongsToMany $permissions) => $permissions->orderBy('module')->orderBy('code')])
            ->withCount('permissions')
            ->addSelect(['members_count' => $this->membersHoldingTheRole()])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @return Builder<CompanyUser>
     */
    protected function membersHoldingTheRole(): Builder
    {
        return CompanyUser::query()
            ->selectRaw('count(*)')
            ->whereColumn('company_user.role_id', 'roles.id');
    }
}
