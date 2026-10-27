<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Companies\Entities\CompanyUser;
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

    /**
     * $user joins $company under $role and accepts the live policy versions there.
     */
    protected function attachMember(Company $company, User $user, Role $role): void
    {
        $company->users()->attach($user, ['role_id' => $role->getKey()]);
        $this->aConsentRecordedBy($user, $company);
    }

    /**
     * Membership without caring about the role — for tests exercising the billing
     * gate, where AuthorizeCompany just needs the user to have joined the company.
     */
    protected function joinCompany(Company $company, User $user): void
    {
        $this->attachMember($company, $user, Role::factory()->create());
    }

    /**
     * Soft-delete ($user)'s membership pivot in $company — the removed-member
     * state (UC-1-024) without going through the endpoint's session kill, so a
     * still-valid session can prove the R7 auth-gate filters trashed memberships.
     */
    protected function softDeleteMembership(Company $company, User $user): void
    {
        CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->update(['deleted_at' => now()]);
    }
}
