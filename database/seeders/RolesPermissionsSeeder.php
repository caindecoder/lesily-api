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

    /**
     * Build the seeder against the bundled catalog YAML. The single source of the
     * deploy/test path wiring (the ctor paths are not container-resolvable, so
     * `$this->call(...)` can't construct it).
     */
    public static function fromDefaultPaths(): self
    {
        return new self(
            rolesYamlPath: database_path('seeders/data/roles.yaml'),
            permissionsYamlPath: database_path('seeders/data/permissions.yaml'),
            rolePermissionsYamlPath: database_path('seeders/data/role-permissions.yaml'),
        );
    }

    public function run(): void
    {
        $rolesData = $this->loadYaml($this->rolesYamlPath);
        $permissionsData = $this->loadYaml($this->permissionsYamlPath);
        $rolePermissionsData = $this->loadYaml($this->rolePermissionsYamlPath);

        DB::transaction(function () use ($rolesData, $permissionsData, $rolePermissionsData): void {
            $this->seedRoles($rolesData);
            $this->seedPermissions($permissionsData);
            $this->seedRolePermissions($rolePermissionsData);
        });
    }

    /**
     * @param  array<mixed>  $rows
     */
    private function seedRoles(array $rows): void
    {
        foreach ($rows as $row) {
            Role::firstOrCreate(
                ['code' => $row['code']],
                Arr::only($row, ['id', 'name', 'description', 'scope', 'is_assignable', 'is_system', 'sort_order'])
            );
        }
    }

    /**
     * @param  array<mixed>  $rows
     */
    private function seedPermissions(array $rows): void
    {
        foreach ($rows as $row) {
            Permission::firstOrCreate(
                ['code' => $row['code']],
                Arr::only($row, ['id', 'name', 'description', 'module'])
            );
        }
    }

    /**
     * @param  array<mixed>  $grants
     */
    private function seedRolePermissions(array $grants): void
    {
        $roleIdByCode = Role::query()->pluck('id', 'code')->all();
        $permissionIdByCode = Permission::query()->pluck('id', 'code')->all();

        foreach ($grants as $roleCode => $permissionCodes) {
            $roleId = $roleIdByCode[$roleCode] ?? throw SeederSourceException::unknownCode($roleCode);
            foreach ($permissionCodes as $permissionCode) {
                $permissionId = $permissionIdByCode[$permissionCode] ?? throw SeederSourceException::unknownCode($permissionCode);
                RolePermission::firstOrCreate([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
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
