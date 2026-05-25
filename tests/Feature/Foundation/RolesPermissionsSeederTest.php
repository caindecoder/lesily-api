<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Database\Seeders\RolesPermissionsSeeder;
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
}
