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

    // Sad — with no token the request never reaches the gate: auth:sanctum
    // rejects it upstream with the standard 401 (UC-1-122 2b). Born-green pin;
    // teeth proven by the lap-2 revert-check (drop auth:sanctum from the probe
    // chain -> the guest reaches the gate and gets 404, so this REDs).
    #[Test]
    public function when_no_token_is_presented_then_unauthenticated(): void
    {
        // Given no authenticated session
        // When a guest probes the platform-admin surface
        $response = $this->probeAdminSurfaceAs(null);

        // Then the request is rejected as unauthenticated
        $this->assertUnauthenticated($response);
    }

    // Sad — a missing or blank PLATFORM_ADMIN_IDS must deny EVERYONE (fail
    // closed): nobody is a platform admin until the env explicitly says so
    // (LD empty-allowlist precedent). Born-green vs the lap-1 deny-all stub;
    // teeth proven by the lap-5 pass-all revert-check.
    #[Test]
    #[DataProvider('provideEmptyAllowlists')]
    public function when_allowlist_is_empty_then_authenticated_user_is_denied(array $allowlist): void
    {
        // Given an authenticated user and an empty allowlist
        $user = User::factory()->create();
        $this->allowlist($allowlist);

        // When they probe the platform-admin surface
        $response = $this->probeAdminSurfaceAs($user);

        // Then the response is the generic resource-not-found envelope (fail closed)
        $this->assertResourceNotFoundNoLeak($response);
    }

    /**
     * The two empty shapes the middleware can meet: the REAL absent-env shape
     * (explode(',', '') === ['']) and an explicitly empty array.
     *
     * @return array<string, array{array<int, string>}>
     */
    public static function provideEmptyAllowlists(): array
    {
        return [
            'absent env (explode yields [""])' => [['']],
            'explicit empty list' => [[]],
        ];
    }

    // Edge — a hand-edited env value arrives with padding, trailing commas, or
    // blank segments (the raw explode shapes config/platform.php hands over);
    // the gate normalizes at the use-site (LD trim/filter idiom) so a genuine
    // member still passes.
    #[Test]
    #[DataProvider('provideMalformedAllowlists')]
    public function when_allowlist_entries_carry_whitespace_or_trailing_commas_then_member_still_passes(callable $shape): void
    {
        // Given an allowlisted user whose env entry is padded or comma-mangled
        $user = User::factory()->create();
        $this->allowlist($shape($user->getKey()));

        // When they probe the platform-admin surface
        $response = $this->probeAdminSurfaceAs($user);

        // Then the gate passes them through
        $this->assertProbePassed($response);
    }

    /**
     * Raw post-explode shapes a hand-edited PLATFORM_ADMIN_IDS produces —
     * each shaper receives the member id at runtime (DP rows are static).
     *
     * @return array<string, array{callable(string): array<int, string>}>
     */
    public static function provideMalformedAllowlists(): array
    {
        return [
            'padded entry' => [fn (string $id): array => [" {$id} "]],
            'trailing comma yields a blank segment' => [fn (string $id): array => [$id, '']],
            'mid-list padding among other entries' => [fn (string $id): array => ['other-uuid', " {$id} ", '']],
        ];
    }

    // Sad — a guest reaching the gate ITSELF (the bare probe mounts
    // platform.admin without upstream auth) under the blank default-env
    // allowlist is denied: the gate's blank-entry filter must never let the
    // guest's blank key match the blank allowlist entry (fail closed —
    // independent-review finding TA-3.1; teeth proven by the lap-10
    // revert-check: deleting the filter arm REDs this at 200).
    #[Test]
    public function when_guest_reaches_the_gate_without_upstream_auth_then_denied(): void
    {
        // Given the blank default-env allowlist and no authenticated session
        $this->allowlist(['']);

        // When a guest probes the bare-mounted gate
        $response = $this->getJson('/api/_test/platform-admin-bare-probe');

        // Then the gate itself denies with the generic no-leak envelope
        $this->assertResourceNotFoundNoLeak($response);
    }

    // Happy — an allowlisted user passes through to the admin surface, whether
    // theirs is the only entry or one among several (the multi-entry row kills
    // any first-element-only membership shortcut).
    #[Test]
    #[DataProvider('provideAllowlistShapes')]
    public function when_allowlisted_user_probes_admin_surface_then_request_passes(callable $shape): void
    {
        // Given a user whose id is on the allowlist
        $user = User::factory()->create();
        $this->allowlist($shape($user->getKey()));

        // When they probe the platform-admin surface
        $response = $this->probeAdminSurfaceAs($user);

        // Then the gate passes them through
        $this->assertProbePassed($response);
    }

    /**
     * Clean allowlist shapes — the caller as sole entry, and mid-list among
     * other admins.
     *
     * @return array<string, array{callable(string): array<int, string>}>
     */
    public static function provideAllowlistShapes(): array
    {
        return [
            'sole entry' => [fn (string $id): array => [$id]],
            'mid-list among other entries' => [fn (string $id): array => ['first-admin-uuid', $id, 'third-admin-uuid']],
        ];
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
