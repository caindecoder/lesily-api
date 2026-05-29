<?php

namespace Tests\Feature\Foundation;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Database\Seeders\RolesPermissionsSeeder;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\RolesPermissions\Entities\Permission;
use App\Foundation\RolesPermissions\Exceptions\SeederSourceException;

class RolesPermissionsSeederTest extends FoundationTestCase
{
    use RefreshDatabase;

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
    public function when_seeder_runs_twice_then_row_counts_are_unchanged(): void
    {
        // Given a valid single-role catalog
        $dir = $this->givenTempCatalog([
            'roles.yaml' => "- code: owner\n".
                "  id: 0193abcd-1111-7000-8000-000000000002\n".
                "  name: Owner\n".
                "  description: Tenant Owner\n".
                "  scope: tenant\n".
                "  is_assignable: true\n".
                "  is_system: true\n".
                "  sort_order: 2\n",
            'permissions.yaml' => '[]',
            'role-permissions.yaml' => 'owner: []',
        ]);
        $seeder = $this->newSeeder($dir);

        // When the seeder runs twice
        $seeder->run();
        $countAfterFirst = DB::table('roles')->count();
        $seeder->run();
        $countAfterSecond = DB::table('roles')->count();

        // Then the row count is unchanged and equals the catalog size (idempotent)
        $this->assertSame(1, $countAfterFirst);
        $this->assertSame($countAfterFirst, $countAfterSecond);
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
        $this->assertSame('0193abcd-1111-7000-8000-000000000002', $owner->id);
        $this->assertOwnerRolePersistedFaithfully($owner);
    }

    #[Test]
    public function when_seeder_runs_then_permission_codes_use_module_action_dot_notation(): void
    {
        // Given the real RBAC catalog
        $this->newSeeder($this->givenRealCatalogPath())->run();

        // Then every seeded permission code matches {module}.{action} dot-notation
        $codes = Permission::query()->pluck('code')->all();
        $this->assertNotEmpty($codes, 'No permissions were seeded.');
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression(
                '/^[a-z_]+\.[a-z_]+$/',
                $code,
                "Permission code '{$code}' is not {module}.{action} dot-notation."
            );
        }
    }

    protected function givenRealCatalogPath(): string
    {
        return database_path('seeders/data');
    }

    #[Test]
    public function when_seeder_runs_then_role_permission_grants_match_locked_matrix_with_manager_empty(): void
    {
        // Given the real RBAC catalog
        $this->newSeeder($this->givenRealCatalogPath())->run();

        // Then per-role grant counts match the locked matrix, and manager has none
        $expected = [
            'platform_super_admin' => 0,
            'owner' => 8,
            'admin' => 7,
            'hr_officer' => 3,
            'payroll_officer' => 3,
            'auditor' => 4,
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

    protected function assertOwnerRolePersistedFaithfully(Role $owner): void
    {
        $this->assertSame('Owner', $owner->name);
        $this->assertSame('tenant', $owner->scope);
        $this->assertTrue($owner->is_assignable);
        $this->assertTrue($owner->is_system);
        $this->assertSame(2, $owner->sort_order);
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

    protected function newSeeder(string $catalogDir): RolesPermissionsSeeder
    {
        return new RolesPermissionsSeeder(
            rolesYamlPath: "{$catalogDir}/roles.yaml",
            permissionsYamlPath: "{$catalogDir}/permissions.yaml",
            rolePermissionsYamlPath: "{$catalogDir}/role-permissions.yaml",
        );
    }
}
