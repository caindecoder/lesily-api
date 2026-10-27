<?php

namespace App\Companies\Memberships\IO\Database\Repositories;

use App\Companies\Entities\CompanyUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Database\Eloquent\Relations\Relation;
use App\Companies\Memberships\UseCases\Repositories\RoleRepositoryInterface;

class EloquentRoleRepository implements RoleRepositoryInterface
{
    public function listAssignableForCompany(string $companyId): Collection
    {
        return Role::query()
            ->where('is_assignable', true)
            ->with(['permissions' => fn (Relation $permissions) => $permissions->orderBy('code')])
            ->withCount('permissions')
            ->addSelect(['members_count' => $this->membersHoldingTheRoleIn($companyId)])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @return Builder<CompanyUser>
     */
    protected function membersHoldingTheRoleIn(string $companyId): Builder
    {
        return CompanyUser::query()
            ->selectRaw('count(*)')
            ->whereColumn('company_user.role_id', 'roles.id')
            ->where('company_user.company_id', $companyId);
    }
}
