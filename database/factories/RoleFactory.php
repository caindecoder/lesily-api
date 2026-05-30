<?php

namespace Database\Factories;

use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Foundation\RolesPermissions\Entities\Permission;
use App\Foundation\RolesPermissions\Entities\RolePermission;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'description' => null,
            'scope' => 'tenant',
            'is_assignable' => true,
            'is_system' => true,
            'sort_order' => 0,
        ];
    }

    public function code(string $code): static
    {
        return $this->state(fn (array $attributes): array => ['code' => $code]);
    }

    /**
     * Grant the role a set of permission codes, creating the permissions on demand.
     *
     * @param  array<int, string>  $codes
     */
    public function withPermissions(array $codes): static
    {
        return $this->afterCreating(function (Role $role) use ($codes): void {
            foreach ($codes as $code) {
                $permission = Permission::firstOrCreate(
                    ['code' => $code],
                    ['name' => $code, 'module' => str($code)->before('.')->value()],
                );

                RolePermission::create([
                    'role_id' => $role->getKey(),
                    'permission_id' => $permission->getKey(),
                ]);
            }
        });
    }
}
