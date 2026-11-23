<?php

namespace Tests;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use App\Consent\Aggregates\Consent;
use Illuminate\Testing\TestResponse;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Consent\UseCases\Commands\RecordConsentCommand;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Every test runs with the supported countries a company is created in and
     * follows (slice-country-settings CS5); a test needing none removes them.
     */
    protected function setUp(): void
    {
        parent::setUp();

        CountriesSeeder::fromDefaultPath()->run();
    }

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

    protected function seededRole(string $code): Role
    {
        return Role::query()->where('code', $code)->sole();
    }

    /**
     * $user's consent to the live policy versions in $company, recorded the
     * event-sourced way from one IP.
     */
    protected function aConsentRecordedBy(User $user, Company $company): void
    {
        Consent::retrieve(Str::uuid7()->toString())
            ->record(RecordConsentCommand::from([
                'user' => $user,
                'company' => $company,
                'policyVersions' => config('lesily.policies'),
                'ip' => '198.51.100.4',
            ]))
            ->persist();
    }

    /**
     * The company-scope claim was rejected by AuthorizeCompany — 403 envelope
     * with COMPANY_ACCESS_DENIED per api-1-infrastructure §4.2.
     */
    protected function assertCompanyAccessDenied(TestResponse $response): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'COMPANY_ACCESS_DENIED');
        $response->assertJsonPath('message', 'You do not have access to this company.');
        $response->assertJsonPath('errors', []);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    protected function assertRoleInsufficient(TestResponse $response, string $permission): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'ROLE_INSUFFICIENT');
        $response->assertJsonPath('message', 'You do not have permission to perform this action.');
        $response->assertJsonPath('required_permission', $permission);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * The request was refused with the §S21 envelope for $code — the caller
     * states the status (a Response::HTTP_* constant), never the enum the
     * code under test renders from (§2.2 Rule 11). Shared by every module base.
     */
    protected function assertRefusedWith(TestResponse $response, int $expectedStatus, ApiErrorCode $code): void
    {
        $response->assertStatus($expectedStatus);
        $response->assertJsonPath('error_code', $code->value);
    }

    /**
     * The input was refused as invalid on $invalidField — 422 VALIDATION_FAILED
     * whose field-level errors contain $expectedMessage (§2.2 Rule 10).
     */
    protected function assertValidationRejected(TestResponse $response, string $invalidField, string $expectedMessage): void
    {
        $response->assertUnprocessable();
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonValidationErrors([$invalidField => $expectedMessage]);
    }

    protected function assertUnauthenticated(TestResponse $response): void
    {
        $response->assertUnauthorized();
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $response->assertJsonPath('message', 'Unauthenticated.');
        $response->assertJsonPath('errors', []);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
        $response->assertJsonMissingPath('data');
    }

    protected function assertResourceNotFound(TestResponse $response): void
    {
        $response->assertNotFound();
        $response->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $response->assertJsonPath('message', 'The requested resource was not found.');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * The action failed closed — 500 INTERNAL_ERROR and no data in the body.
     */
    protected function assertFailedClosed(TestResponse $response): void
    {
        $response->assertInternalServerError();
        $response->assertJsonPath('error_code', 'INTERNAL_ERROR');
        $response->assertJsonMissingPath('data');
    }
}
