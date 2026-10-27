<?php

namespace App\Companies\Memberships\IO\Database\Repositories;

use Illuminate\Database\Eloquent\Collection;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Companies\Memberships\UseCases\Repositories\RoleRepositoryInterface;

class EloquentRoleRepository implements RoleRepositoryInterface
{
    public function listAssignableForCompany(string $companyId): Collection
    {
        return Role::query()
            ->where('is_assignable', true)
            ->orderBy('sort_order')
            ->get();
    }
}
