<?php

namespace Tests\Feature\Foundation;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
