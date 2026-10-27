<?php

namespace Tests\Feature\Foundation;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Database\Seeders\RolesPermissionsSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Foundation\RolesPermissions\Entities\Permission;
use App\Foundation\RolesPermissions\Exceptions\SeederSourceException;

/**
 * @testclass-carveout: seeder — the artifact IS the behavior (the RBAC catalog
 * seeder and its source-file contract; conv-plan §2.14 Rule B).
 */
class RolesPermissionsSeederTest extends FoundationTestCase
{
    #[Test]
    public function when_source_file_missing_then_seeder_throws_with_file_path(): void
    {
        // Given a seeder configured to read from a non-existent path
        $seeder = $this->newSeeder('/nonexistent/rbac-catalog');

        // When the seeder runs
        // Then it throws SeederSourceException naming the missing file
        $this->expectException(SeederSourceException::class);
        $this->expectExceptionMessage('/nonexistent/rbac-catalog/roles.yaml');

        $seeder->run();
    }

    #[Test]
    public function when_source_file_malformed_then_seeder_throws_and_persists_no_rows(): void
    {
        // Given a catalog whose permissions file is unparseable YAML (roles file valid)
        $dir = $this->givenTempCatalog([
            'roles.yaml' => "- code: owner\n  name: Owner\n",
            'permissions.yaml' => 'invalid: yaml: : : malformed: bad: :',
            'role-permissions.yaml' => "owner: []\n",
        ]);

        // When the seeder runs
        // Then it throws naming the failing file and persists no rows (transaction integrity)
        try {
            $this->newSeeder($dir)->run();
            $this->fail('SeederSourceException was not thrown.');
        } catch (SeederSourceException $e) {
            $this->assertStringContainsString('permissions.yaml', $e->getMessage());
            $this->assertSame(0, DB::table('roles')->count());
        }
    }

    #[Test]
    #[DataProvider('unknownGrantCodeProvider')]
    public function when_grant_references_unknown_code_then_seeder_throws_and_rolls_back(string $grantsYaml, string $badCode): void
    {
        // Given valid roles + permissions but a grant referencing a code absent from the catalog
        $dir = $this->givenTempCatalog([
            'roles.yaml' => "- code: owner\n".
                "  id: 0193abcd-1111-7000-8000-000000000002\n".
                "  name: Owner\n  description: Owner\n  scope: tenant\n".
                "  is_assignable: true\n  is_system: true\n  sort_order: 2\n",
            'permissions.yaml' => "- code: users.view\n".
                "  id: 0193abcd-2222-7000-8000-000000000002\n".
                "  name: View Users\n  description: View\n  module: users\n",
            'role-permissions.yaml' => $grantsYaml,
        ]);

        // When the seeder runs
        // Then it throws naming the bad code and rolls back every table (no partial state)
        try {
            $this->newSeeder($dir)->run();
            $this->fail('SeederSourceException was not thrown.');
        } catch (SeederSourceException $e) {
            $this->assertStringContainsString($badCode, $e->getMessage());
            $this->assertSame(0, DB::table('roles')->count());
            $this->assertSame(0, DB::table('permissions')->count());
            $this->assertSame(0, DB::table('role_permission')->count());
        }
    }

    #[Test]
    public function when_seeder_runs_twice_then_all_table_counts_are_unchanged(): void
    {
        // Given the real RBAC catalog
        $seeder = $this->newSeeder($this->givenRealCatalogPath());

        // When the seeder runs twice
        $seeder->run();
        $afterFirst = $this->tableCounts();
        $seeder->run();
        $afterSecond = $this->tableCounts();

        // Then every table's row count is stable across runs (idempotent — no duplicates)
        $this->assertSame(['roles' => 8, 'permissions' => 15, 'role_permission' => 39], $afterFirst);
        $this->assertSame($afterFirst, $afterSecond);
    }

    #[Test]
    public function when_seeder_runs_then_eight_roles_persisted_with_canonical_codes_and_stable_uuids(): void
    {
        // Given the real RBAC catalog shipped in database/seeders/data
        $seeder = $this->newSeeder($this->givenRealCatalogPath());

        // When the seeder runs
        $seeder->run();

        // Then the 8 canonical role codes are persisted in sort order
        $codes = Role::query()->orderBy('sort_order')->pluck('code')->all();
        $this->assertSame([
            'platform_super_admin',
            'owner',
            'admin',
            'hr_officer',
            'payroll_officer',
            'auditor',
            'employee',
            'manager',
        ], $codes);

        // And the owner role is persisted faithfully with its committed stable UUID
        $owner = Role::query()->where('code', 'owner')->firstOrFail();
        $this->assertSame('0193abcd-1111-7000-8000-000000000002', $owner->getKey());
        $this->assertOwnerRolePersistedFaithfully($owner);
    }

    #[Test]
    public function when_seeder_runs_then_permission_codes_use_module_action_dot_notation(): void
    {
        // Given the real RBAC catalog
        $this->newSeeder($this->givenRealCatalogPath())->run();

        // Then every seeded permission code matches {module}.{action} dot-notation
        // (a multi-word action may hyphenate — D10: users.revoke-invitation, users.change-role)
        $codes = Permission::query()->pluck('code')->all();
        $this->assertNotEmpty($codes, 'No permissions were seeded.');
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression(
                '/^[a-z_]+\.[a-z_-]+$/',
                $code,
                "Permission code '{$code}' is not {module}.{action} dot-notation."
            );
        }

        // And a representative permission is persisted faithfully (name/description/module mapping)
        $this->assertInviteUsersPermissionPersistedFaithfully();
        // And the D10-renamed code is pinned directly — a YAML revert to
        // users.assign_role must fail HERE, not as a 403 three files away
        // (pr-test-analyzer IMPORTANT-2, s112).
        $this->assertChangeRolePermissionPersistedFaithfully();
        // And users.remove is pinned the same way (D10, UC-1-024, s113).
        $this->assertRemovePermissionPersistedFaithfully();
    }

    #[Test]
    public function when_seeder_runs_then_role_permission_grants_match_locked_matrix_with_manager_empty(): void
    {
        // Given the real RBAC catalog
        $this->newSeeder($this->givenRealCatalogPath())->run();

        // Then per-role grant counts match the locked matrix, and manager has none
        $expected = [
            'platform_super_admin' => 0,
            'owner' => 15,
            'admin' => 12,
            'hr_officer' => 5,
            // 1 since billing.view came out too (matrix §Module-6 ❌ — F2/D-A,
            // slice-plans-billing T1, s130); users.view left at D-A, s108.
            'payroll_officer' => 1,
            'auditor' => 5,
            'employee' => 1,
            'manager' => 0,
        ];
        $actual = Role::query()
            ->withCount('permissions')
            ->get()
            ->mapWithKeys(fn (Role $role) => [$role->code => $role->permissions_count])
            ->all();

        $this->assertRoleGrantCountsMatchMatrix($expected, $actual);
        $this->assertSame(0, $actual['manager'], 'Manager must have zero grants (chunk-1 placeholder).');
    }

    #[Test]
    public function when_owner_role_loaded_by_code_then_permissions_eager_loaded_without_n_plus_one(): void
    {
        // Given the seeded catalog
        $this->newSeeder($this->givenRealCatalogPath())->run();

        // When owner is looked up by code and its permissions eager-loaded
        DB::flushQueryLog();
        DB::enableQueryLog();
        $owner = Role::findByCode('owner');
        $this->assertNotNull($owner, 'Owner role must be seeded.');
        $owner->load('permissions');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Then owner has its full permission set in exactly 2 queries (no N+1)
        $this->assertGreaterThan(0, $owner->permissions->count(), 'Owner should have permission grants.');
        $this->assertSame(2, $queryCount, "Expected 2 queries (role + permissions), got {$queryCount}.");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unknownGrantCodeProvider(): array
    {
        return [
            'unknown role code' => ["nonexistent_role:\n  - users.view\n", 'nonexistent_role'],
            'unknown permission code' => ["owner:\n  - users.nonexistent\n", 'users.nonexistent'],
        ];
    }

    protected function newSeeder(string $catalogDir): RolesPermissionsSeeder
    {
        return new RolesPermissionsSeeder(
            rolesYamlPath: "{$catalogDir}/roles.yaml",
            permissionsYamlPath: "{$catalogDir}/permissions.yaml",
            rolePermissionsYamlPath: "{$catalogDir}/role-permissions.yaml",
        );
    }

    /**
     * @param  array<string, string>  $files
     */
    protected function givenTempCatalog(array $files): string
    {
        $dir = sys_get_temp_dir().'/rbac-seeder-'.uniqid();
        mkdir($dir);
        foreach ($files as $name => $contents) {
            file_put_contents("{$dir}/{$name}", $contents);
        }

        return $dir;
    }

    protected function givenRealCatalogPath(): string
    {
        return database_path('seeders/data');
    }

    /**
     * @return array{roles: int, permissions: int, role_permission: int}
     */
    protected function tableCounts(): array
    {
        return [
            'roles' => DB::table('roles')->count(),
            'permissions' => DB::table('permissions')->count(),
            'role_permission' => DB::table('role_permission')->count(),
        ];
    }

    protected function assertOwnerRolePersistedFaithfully(Role $owner): void
    {
        $this->assertSame([
            'name' => 'Owner',
            'description' => 'Tenant Owner — billing, ownership, and final authority. Exactly one per company.',
            'scope' => 'tenant',
            'is_assignable' => true,
            'is_system' => true,
            'sort_order' => 2,
        ], $owner->only(['name', 'description', 'scope', 'is_assignable', 'is_system', 'sort_order']));
    }

    protected function assertInviteUsersPermissionPersistedFaithfully(): void
    {
        $permission = Permission::query()->where('code', 'users.invite')->firstOrFail();
        $this->assertSame([
            'name' => 'Invite Users',
            'description' => 'Send invitations to new users to join the company.',
            'module' => 'users',
        ], $permission->only(['name', 'description', 'module']));
    }

    protected function assertChangeRolePermissionPersistedFaithfully(): void
    {
        $permission = Permission::query()->where('code', 'users.change-role')->firstOrFail();
        $this->assertSame([
            'name' => 'Change User Role',
            'description' => "Change a user's role within the company.",
            'module' => 'users',
        ], $permission->only(['name', 'description', 'module']));
    }

    protected function assertRemovePermissionPersistedFaithfully(): void
    {
        $permission = Permission::query()->where('code', 'users.remove')->firstOrFail();
        $this->assertSame([
            'name' => 'Remove Users',
            'description' => "Remove a user's access to the company.",
            'module' => 'users',
        ], $permission->only(['name', 'description', 'module']));
    }

    /**
     * @param  array<string, int>  $expected
     * @param  array<string, int>  $actual
     */
    protected function assertRoleGrantCountsMatchMatrix(array $expected, array $actual): void
    {
        foreach ($expected as $code => $expectedCount) {
            $this->assertArrayHasKey($code, $actual, "Role '{$code}' was not seeded.");
            $this->assertSame(
                $expectedCount,
                $actual[$code],
                "Role '{$code}' grant count drift: expected {$expectedCount}, got {$actual[$code]}."
            );
        }
    }
}
