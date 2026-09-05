<?php

namespace Tests\Feature\Foundation;

use Database\Seeders\DatabaseSeeder;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Foundation\RolesPermissions\Entities\Permission;
use App\Foundation\RolesPermissions\Entities\RolePermission;

/**
 * @testclass-carveout: seeder — the artifact IS the behavior (the production
 * db:seed entry point; conv-plan §2.14 Rule B).
 */
class DatabaseSeederTest extends FoundationTestCase
{
    // OQ-1-110 — production `db:seed` must stand up the whole RBAC catalog:
    // owner signup enrolls the creator against the 'owner' role AND authorization
    // resolves that role's permissions, so a catalog-less (or partially-wired)
    // deploy would 500 every registration or silently under-grant the owner.
    #[Test]
    public function when_the_database_is_seeded_then_the_rbac_catalog_is_available(): void
    {
        // When the application database is seeded
        $this->seed(DatabaseSeeder::class);

        // Then the roles, permissions, and their mappings are all present
        $this->assertRbacCatalogSeeded();
    }

    protected function assertRbacCatalogSeeded(): void
    {
        $owner = Role::findByCode('owner');

        $this->assertNotNull($owner, 'The owner role the registration flow enrols against must exist.');
        $this->assertTrue(
            Permission::query()->where('code', 'users.view')->exists(),
            'The permission catalog must be seeded.',
        );
        $this->assertTrue(
            RolePermission::query()->where('role_id', $owner->getKey())->exists(),
            'Owner role-permission mappings must be seeded.',
        );
    }
}
