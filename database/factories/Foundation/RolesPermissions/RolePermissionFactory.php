<?php

namespace Database\Factories\Foundation\RolesPermissions;

use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Foundation\RolesPermissions\Entities\Permission;
use App\Foundation\RolesPermissions\Entities\RolePermission;

/**
 * @extends Factory<RolePermission>
 */
class RolePermissionFactory extends Factory
{
    protected $model = RolePermission::class;

    public function definition(): array
    {
        return [
            'role_id' => Role::factory(),
            'permission_id' => Permission::factory(),
        ];
    }
}
