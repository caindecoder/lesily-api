<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Foundation\RolesPermissions\Exceptions\SeederSourceException;

class RolesPermissionsSeeder extends Seeder
{
    public function __construct(
        private readonly string $rolesYamlPath = '',
        private readonly string $permissionsYamlPath = '',
        private readonly string $rolePermissionsYamlPath = '',
    ) {}

    public function run(): void
    {
        if (! is_file($this->rolesYamlPath)) {
            throw SeederSourceException::missingFile($this->rolesYamlPath);
        }
        // Permissions + role-permissions checks land in Test #2's GREEN phase.
    }
}
