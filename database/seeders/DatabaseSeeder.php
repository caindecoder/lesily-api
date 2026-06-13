<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * The RBAC catalog (UC-1-136) is a deploy precondition, not demo data:
     * owner signup enrolls the creator against the seeded 'owner' role, so
     * `db:seed` must run it on every environment (OQ-1-110). Instantiated
     * directly because the seeder's YAML-source paths are not container-resolvable.
     */
    public function run(): void
    {
        RolesPermissionsSeeder::fromDefaultPaths()->run();
    }
}
