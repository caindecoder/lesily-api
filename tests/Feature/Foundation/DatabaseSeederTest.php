<?php

namespace Tests\Feature\Foundation;

use Database\Seeders\DatabaseSeeder;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DatabaseSeederTest extends FoundationTestCase
{
    use RefreshDatabase;

    // OQ-1-110 — production `db:seed` must stand up the RBAC catalog: owner
    // signup enrolls the creator against Role::findByCode('owner'), so a
    // catalog-less deploy would 500 every registration.
    #[Test]
    public function when_the_database_is_seeded_then_the_owner_role_is_available(): void
    {
        // When the application database is seeded
        $this->seed(DatabaseSeeder::class);

        // Then the owner role the registration flow depends on exists
        $this->assertNotNull(Role::findByCode('owner'));
    }
}
