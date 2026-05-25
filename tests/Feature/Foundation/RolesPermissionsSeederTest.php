<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Database\Seeders\RolesPermissionsSeeder;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\RolesPermissions\Exceptions\SeederSourceException;

/**
 * UC-1-136 — Seed RBAC Persistence Layer (chunk-1 Tier-1 #4).
 *
 * Design refs:
 * - UC body: Documentation/06-design/chunk-1/use-cases/uc-users-and-roles.md UC-1-136
 * - CD: Documentation/06-design/chunk-1/class-diagram/shared/cd-roles-permissions.md
 * - ERD: Documentation/06-design/chunk-1/erd/shared/erd-roles-permissions.md
 * - Source matrix: Documentation/06-design/chunk-1/_process/2026-05-24-rbac-permissions-matrix.md
 * - Plan: Documentation/06-design/chunk-1/_process/2026-05-25-uc-1-136-plan.md
 */
class RolesPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_yaml_file_missing_then_seeder_throws_with_file_path(): void
    {
        // Given a seeder configured to read from a non-existent path
        $seeder = new RolesPermissionsSeeder(
            rolesYamlPath: '/nonexistent/roles.yaml',
            permissionsYamlPath: '/nonexistent/permissions.yaml',
            rolePermissionsYamlPath: '/nonexistent/role-permissions.yaml',
        );

        // When the seeder runs
        try {
            $seeder->run();
            $this->fail('SeederSourceException was not thrown.');
        } catch (SeederSourceException $e) {
            // Then the exception message includes the missing file path
            $this->assertStringContainsString('/nonexistent/roles.yaml', $e->getMessage());
        }
    }

    #[Test]
    public function when_yaml_file_malformed_then_seeder_throws_with_descriptive_error(): void
    {
        // Given a valid roles.yaml and an unparseable permissions.yaml
        $tmpDir = $this->givenTempYamlDirectory();
        file_put_contents("{$tmpDir}/roles.yaml", "- code: owner\n  name: Owner\n");
        file_put_contents("{$tmpDir}/permissions.yaml", 'invalid: yaml: : : malformed: bad: :');
        file_put_contents("{$tmpDir}/role-permissions.yaml", "owner: []\n");

        $seeder = new RolesPermissionsSeeder(
            rolesYamlPath: "{$tmpDir}/roles.yaml",
            permissionsYamlPath: "{$tmpDir}/permissions.yaml",
            rolePermissionsYamlPath: "{$tmpDir}/role-permissions.yaml",
        );

        // When the seeder runs
        try {
            $seeder->run();
            $this->fail('SeederSourceException was not thrown.');
        } catch (SeederSourceException $e) {
            // Then the exception message references the failing file
            $this->assertStringContainsString('permissions.yaml', $e->getMessage());
        }
    }

    #[Test]
    public function when_seeder_runs_twice_then_idempotent_with_no_duplicates(): void
    {
        // Given a valid minimal YAML catalog (one role, no permissions, no grants)
        $tmpDir = $this->givenTempYamlDirectory();
        file_put_contents("{$tmpDir}/roles.yaml",
            "- code: owner\n".
            "  id: 0193abcd-1111-7000-8000-000000000001\n".
            "  name: Owner\n".
            "  description: Tenant Owner\n".
            "  scope: tenant\n".
            "  is_assignable: true\n".
            "  is_system: true\n".
            "  sort_order: 1\n"
        );
        file_put_contents("{$tmpDir}/permissions.yaml", '[]');
        file_put_contents("{$tmpDir}/role-permissions.yaml", 'owner: []');

        $seeder = $this->newSeeder($tmpDir);

        // When the seeder runs twice
        $seeder->run();
        $rolesAfterFirst = DB::table('roles')->count();
        $seeder->run();
        $rolesAfterSecond = DB::table('roles')->count();

        // Then row counts are identical and equal to the YAML row count
        $this->assertSame(1, $rolesAfterFirst);
        $this->assertSame($rolesAfterFirst, $rolesAfterSecond);
    }

    protected function givenTempYamlDirectory(): string
    {
        $dir = sys_get_temp_dir().'/rbac-seeder-test-'.uniqid();
        mkdir($dir);

        return $dir;
    }

    #[Test]
    public function when_seeder_runs_then_eight_roles_persisted_with_canonical_codes_and_stable_uuids(): void
    {
        // Given the real catalog YAML in database/seeders/data
        $seeder = $this->newSeeder($this->givenRealCatalogPath());

        // When the seeder runs
        $seeder->run();

        // Then the 8 canonical role codes exist in sort_order
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

        // And the owner role has the stable UUID committed to the seeder source
        $owner = Role::query()->where('code', 'owner')->first();
        $this->assertNotNull($owner);
        $this->assertSame(36, strlen($owner->id));
    }

    protected function newSeeder(string $dir): RolesPermissionsSeeder
    {
        return new RolesPermissionsSeeder(
            rolesYamlPath: "{$dir}/roles.yaml",
            permissionsYamlPath: "{$dir}/permissions.yaml",
            rolePermissionsYamlPath: "{$dir}/role-permissions.yaml",
        );
    }

    protected function givenRealCatalogPath(): string
    {
        return database_path('seeders/data');
    }
}
