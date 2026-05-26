<?php

namespace Database\Seeders;

use Illuminate\Support\Arr;
use Illuminate\Database\Seeder;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Support\Facades\DB;
use App\Foundation\RolesPermissions\Entities\Role;
use Symfony\Component\Yaml\Exception\ParseException;
use App\Foundation\RolesPermissions\Entities\Permission;
use App\Foundation\RolesPermissions\Entities\RolePermission;
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
        $permissionsData = $this->loadYaml($this->permissionsYamlPath);
        $rolePermissionsData = $this->loadYaml($this->rolePermissionsYamlPath);

        DB::transaction(function () use ($rolesData, $permissionsData, $rolePermissionsData) {
            foreach ($rolesData as $row) {
                Role::firstOrCreate(
                    ['code' => $row['code']],
                    Arr::only($row, ['id', 'name', 'description', 'scope', 'is_assignable', 'is_system', 'sort_order'])
                );
            }
            foreach ($permissionsData as $row) {
                Permission::firstOrCreate(
                    ['code' => $row['code']],
                    Arr::only($row, ['id', 'name', 'description', 'module'])
                );
            }
            $roleIdByCode = Role::query()->pluck('id', 'code')->all();
            $permissionIdByCode = Permission::query()->pluck('id', 'code')->all();
            foreach ($rolePermissionsData as $roleCode => $permissionCodes) {
                if (! isset($roleIdByCode[$roleCode])) {
                    continue;
                }
                foreach ((array) $permissionCodes as $permissionCode) {
                    if (! isset($permissionIdByCode[$permissionCode])) {
                        continue;
                    }
                    RolePermission::firstOrCreate([
                        'role_id' => $roleIdByCode[$roleCode],
                        'permission_id' => $permissionIdByCode[$permissionCode],
                    ]);
                }
            }
        });
    }

    /**
     * @return array<mixed>
     */
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
