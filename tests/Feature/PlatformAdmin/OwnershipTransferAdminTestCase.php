<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Shared fixtures for the ownership-transfer PLATFORM-ADMIN reads (UC-1-127,
 * slice #11 Task 3) — the cross-tenant queue + detail behind auth:sanctum +
 * platform.admin. Sits under PlatformAdminTestCase so the admin-generic members
 * (allowlist, provideTenantRoles, the 401/404 envelope asserts) are inherited
 * while the ownership-transfer fixtures stay out of the invoice-heavy base
 * (§2.10 + E09 — one base per actor+subject pair).
 */
abstract class OwnershipTransferAdminTestCase extends PlatformAdminTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The 404-denial DataProvider composes real seeded tenant roles.
        $this->seedRbacCatalog();
        // Error-path render parity (amendment M): APP_DEBUG=true masks the prod
        // render path, so rejections must render their mapped envelopes.
        config(['app.debug' => false]);
    }

    /**
     * GET the cross-tenant admin queue as $caller, or as a guest when null.
     * Deliberately NO X-Company-Context — the /api/v1/admin/* family carries no
     * auth.company (cd-foundation-http §3).
     *
     * @param  array<string, mixed>  $query
     */
    protected function getAdminQueue(?User $caller, array $query = []): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        $url = '/api/v1/admin/ownership-transfers'.($query === [] ? '' : '?'.http_build_query($query));

        return $this->getJson($url);
    }
}
