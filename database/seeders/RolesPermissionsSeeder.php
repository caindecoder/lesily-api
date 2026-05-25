<?php

namespace Database\Seeders;

use Illuminate\Support\Arr;
use Illuminate\Database\Seeder;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Support\Facades\DB;
use App\Foundation\RolesPermissions\Entities\Role;
use Symfony\Component\Yaml\Exception\ParseException;
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
        $rolesData = $this->loadYaml($this->rolesYamlPath);
        $this->loadYaml($this->permissionsYamlPath);
        $this->loadYaml($this->rolePermissionsYamlPath);

        DB::transaction(function () use ($rolesData) {
            foreach ($rolesData as $row) {
                Role::firstOrCreate(
                    ['code' => $row['code']],
                    Arr::only($row, ['id', 'name', 'description', 'scope', 'is_assignable', 'is_system', 'sort_order'])
                );
            }
            // Permissions + role-permission persistence land in later cycles.
        });
    }

    private function loadYaml(string $path): array
    {
        if (! is_file($path)) {
            throw SeederSourceException::missingFile($path);
        }
        try {
            return Yaml::parseFile($path) ?? [];
        } catch (ParseException $e) {
            throw SeederSourceException::parseFailure($path, $e);
        }
    }
}
