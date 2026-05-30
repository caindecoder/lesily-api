<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Foundation\RolesPermissions\Entities\Role;

/**
 * Shared fixtures for the two-gate (billing + role) feature tests.
 *
 * Mirrors the LeaveDates per-test factory style (no global seeder run): callers
 * compose a company + member with factory states, then drive the gated probe
 * routes in routes/testing.php through actingAs() + the X-Company-Context header
 * that the two-gate middleware resolve the company from.
 */
trait WithRolePermissionFixtures
{
    protected function attemptGatedAction(string $route, User $user, Company $company): TestResponse
    {
        return $this->actingAs($user)->getJson(
            "/api/_test/{$route}",
            ['X-Company-Context' => $company->getKey()],
        );
    }

    protected function attachMember(Company $company, User $user, Role $role): void
    {
        $company->users()->attach($user, ['role_id' => $role->getKey()]);
    }
}
