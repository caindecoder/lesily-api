<?php

namespace Tests;

use Illuminate\Testing\TestResponse;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Seed the UC-1-136 RBAC catalog. Every event-sourced company genesis enrolls
     * its owner against the seeded 'owner' role (CompanyProjector), so any test
     * arranging a company through the aggregate needs the catalog in place —
     * production runs the seeder at deploy. Idempotent (firstOrCreate).
     */
    protected function seedRbacCatalog(): void
    {
        RolesPermissionsSeeder::fromDefaultPaths()->run();
    }

    /**
     * The company-scope claim was rejected by AuthorizeCompany — 403 envelope
     * with COMPANY_ACCESS_DENIED per api-1-infrastructure §4.2. Hoisted from
     * FoundationTestCase (s121, UC-1-032 — pure relocation): the Foundation
     * middleware/two-gate suites and the Companies route-param suite share it.
     */
    protected function assertCompanyAccessDenied(TestResponse $response): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'COMPANY_ACCESS_DENIED');
        $response->assertJsonPath('message', 'You do not have access to this company.');
        $response->assertJsonPath('errors', []);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }
}
