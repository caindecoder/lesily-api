<?php

namespace Database\Factories\Foundation\RolesPermissions;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Foundation\RolesPermissions\Entities\Permission;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    public function definition(): array
    {
        $module = $this->faker->randomElement(['users', 'companies', 'billing', 'audit']);
        $action = $this->faker->randomElement(['view', 'create', 'update', 'delete']);

        return [
            'code' => "{$module}.{$action}_".$this->faker->unique()->numberBetween(1, 1000000),
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'module' => $module,
        ];
    }
}
