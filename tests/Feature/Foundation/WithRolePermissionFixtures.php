<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Foundation\RolesPermissions\Entities\Role;

/**
 * Shared fixtures for the two-gate (billing + role) feature tests.
 *
 * Mirrors the LeaveDates per-test factory style (no global seeder run): callers
 * compose a company + member with factory states, then drive the gated probe
 * routes in routes/testing.php through Sanctum::actingAs() + the X-Company-Context
 * header that AuthorizeCompany resolves and validates membership against.
 */
trait WithRolePermissionFixtures
{
    protected function attemptGatedAction(string $route, User $user, Company $company): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->getJson(
            "/api/_test/{$route}",
            ['X-Company-Context' => $company->getKey()],
        );
    }

    protected function attachMember(Company $company, User $user, Role $role): void
    {
        $company->users()->attach($user, ['role_id' => $role->getKey()]);
    }

    /**
     * Membership without caring about the role — for tests exercising the billing
     * gate, where AuthorizeCompany just needs the user to have joined the company.
     */
    protected function joinCompany(Company $company, User $user): void
    {
        $this->attachMember($company, $user, Role::factory()->create());
    }
}
