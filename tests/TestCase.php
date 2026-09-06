<?php

namespace Tests;

use Illuminate\Testing\TestResponse;
use Database\Seeders\RolesPermissionsSeeder;
use App\Foundation\Http\Responses\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Infection mutant processes carry INFECTION=1 (never Infection's initial run)
     * and reuse whatever schema their database already holds: an incremental
     * migrate builds an empty token database once and is a no-op after that.
     * Every other runner migrates fresh per process. Rule: conventions plan
     * section 2.1 (database test-trait placement).
     */
    protected function migrateDatabases(): void
    {
        if (getenv('INFECTION') === false) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());

            return;
        }

        $this->artisan('migrate');
    }

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

    /**
     * The request was refused with the §S21 envelope for $code. The caller
     * states the status (a Response::HTTP_* constant) — it is never read from
     * the enum the production path renders from, so an arm change fails here.
     * Conventions §2.2 Rule 11 (OQ-1-177). Shared by every module base.
     */
    protected function assertRefusedWith(TestResponse $response, int $expectedStatus, ApiErrorCode $code): void
    {
        $response->assertStatus($expectedStatus);
        $response->assertJsonPath('error_code', $code->value);
    }
}
