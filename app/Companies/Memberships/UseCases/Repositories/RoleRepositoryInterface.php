<?php

namespace App\Companies\Memberships\UseCases\Repositories;

use Illuminate\Database\Eloquent\Collection;
use App\Foundation\RolesPermissions\Entities\Role;

interface RoleRepositoryInterface
{
    /**
     * @return Collection<int, Role>
     */
    public function listAssignableForCompany(string $companyId): Collection;
}
