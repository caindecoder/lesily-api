<?php

namespace Database\Factories\Foundation\RolesPermissions;

use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->slug(2, false),
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'scope' => 'tenant',
            'is_assignable' => true,
            'is_system' => false,
            'sort_order' => 0,
        ];
    }
}
