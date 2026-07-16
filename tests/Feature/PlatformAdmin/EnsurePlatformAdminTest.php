<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Slice-plans-billing T6 — the /api/v1/admin/* family's no-info-leak gate.
 * EnsurePlatformAdmin (alias platform.admin) over the config/platform.php env
 * allowlist (B2 — INTERIM binding, replaced by the platform-admin slice;
 * cd-foundation-http §3). Driven end-to-end through the routes/testing.php
 * probe (§2.11): auth:sanctum then platform.admin, deliberately NO auth.company.
 */
class EnsurePlatformAdminTest extends PlatformAdminTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();

        // Error-path tests must exercise the production render path (the
        // bootstrap catch-all behaves differently under debug — amendment M).
        config(['app.debug' => false]);
    }

    // Sad — the admin surface never acknowledges its existence to a tenant-side
    // caller: every seeded role — INCLUDING a platform_super_admin ROLE-holder
    // who is not on the allowlist (the B2 INTERIM binding is id-based, not
    // role-based) — gets the byte-identical generic 404 (UC-1-122 2a).
    #[Test]
    #[DataProvider('provideNonAllowlistedRoles')]
    public function when_tenant_member_probes_admin_surface_then_resource_not_found(string $roleCode): void
    {
        // Given a member under a seeded role, while the allowlist names only a different user
        $member = $this->aTenantMember($roleCode);
        $this->allowlist([User::factory()->create()->getKey()]);

        // When they probe the platform-admin surface
        $response = $this->probeAdminSurfaceAs($member);

        // Then the response is the generic resource-not-found envelope (no leak)
        $this->assertResourceNotFoundNoLeak($response);
    }

    /**
     * Every seeded role the gate must turn away — the 7 tenant roles plus the
     * platform_super_admin role-without-allowlist row (allowlist membership,
     * not role, is the B2 source of truth this slice).
     *
     * @return array<string, array{string}>
     */
    public static function provideNonAllowlistedRoles(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
            'manager' => ['manager'],
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'auditor' => ['auditor'],
            'platform_super_admin role without allowlist entry' => ['platform_super_admin'],
        ];
    }
}
