<?php

namespace Tests\Feature\PlatformAdmin;

use Tests\TestCase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Illuminate\Database\QueryException;
use App\Foundation\RolesPermissions\Entities\Role;

abstract class PlatformAdminTestCase extends TestCase
{
    /**
     * Set the platform-admin allowlist exactly as the middleware will see it —
     * raw post-explode shapes (config/platform.php does the bare env split;
     * normalization is the middleware's job, so tests feed it raw arrays).
     */
    protected function allowlist(array $ids): void
    {
        config(['platform.admin_user_ids' => $ids]);
    }

    /**
     * Probe the platform.admin-gated surface (routes/testing.php) as $user, or
     * as a guest when null. Deliberately NO X-Company-Context header — the
     * /api/v1/admin/* family is cross-tenant by design, with no auth.company
     * in its chain (cd-foundation-http §3).
     */
    protected function probeAdminSurfaceAs(?User $user): TestResponse
    {
        if ($user !== null) {
            Sanctum::actingAs($user);
        }

        return $this->getJson('/api/_test/platform-admin-probe');
    }

    /**
     * Look up a seeded RBAC-catalog role by code — requires seedRbacCatalog()
     * in the calling suite's setUp. Duplicated from BillingTestCase (module
     * bases are independent — established precedent).
     */
    protected function seededRole(string $code): Role
    {
        return Role::query()->where('code', $code)->sole();
    }

    /**
     * A fresh user made a live member of a fresh company under a seeded role —
     * the tenant-side caller the admin gate must turn away.
     */
    protected function aTenantMember(string $roleCode): User
    {
        $user = User::factory()->create();

        Company::factory()->create()
            ->users()->attach($user, ['role_id' => $this->seededRole($roleCode)->getKey()]);

        return $user;
    }

    /**
     * The admin surface refused the caller with the generic 404 §S21 envelope —
     * byte-identical to any real not-found, so the /api/v1/admin/* family never
     * acknowledges its existence to non-admins (UC-1-122 2a no-info-leak).
     */
    protected function assertResourceNotFoundNoLeak(TestResponse $response): void
    {
        $response->assertNotFound();
        $response->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $response->assertJsonPath('message', 'The requested resource was not found.');
        $response->assertJsonPath('errors', []);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * The request was rejected as unauthenticated — 401 §S21 UNAUTHENTICATED.
     * Duplicated from BillingTestCase (module bases are independent —
     * established precedent).
     */
    protected function assertUnauthenticated(TestResponse $response): void
    {
        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * The gate passed the caller through to the probe body.
     */
    protected function assertProbePassed(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertExactJson(['ok' => true]);
    }

    /**
     * The insert was rejected by the NOT NULL constraint on $column — the
     * SPECIFIC integrity violation (SQLSTATE 23000) named in the DRIVER
     * message (errorInfo[2]), not the rendered query: the exception message
     * embeds the full insert column list, so matching it would false-pass
     * for a violation on any other column (review finding CR-M-1). A missing
     * table (42S02) cannot satisfy the assertion either.
     */
    protected function assertNotNullViolationOn(QueryException $e, string $column): void
    {
        $this->assertSame('23000', (string) $e->getCode());
        $this->assertStringContainsString(
            "Column '{$column}' cannot be null",
            (string) ($e->errorInfo[2] ?? ''),
        );
    }
}
