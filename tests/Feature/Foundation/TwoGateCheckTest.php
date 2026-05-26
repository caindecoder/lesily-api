<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;
use App\Models\User;
use App\Models\Company;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\Feature;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * UC-1-055 — Access Gated Feature (Two-Gate Check) (chunk-1 Tier-1 #5).
 *
 * Design refs:
 * - UC body: Documentation/06-design/chunk-1/use-cases/uc-plans-billing-gating.md UC-1-055 (L1559+)
 * - CDs: cd-foundation-http.md L209-221 (EnsureFeatureAvailable + CheckPermissionMiddleware)
 *        cd-roles-permissions.md L150+ (PermissionEvaluator)
 * - api-conventions §11 (two-gate model + error codes FEATURE_NOT_AVAILABLE / ROLE_INSUFFICIENT)
 * - Spec: Documentation/06-design/chunk-1/_process/2026-05-25-uc-1-055-preflight-design.md
 * - Plan: Documentation/06-design/chunk-1/_process/2026-05-25-uc-1-055-plan.md
 * - Predecessor: UC-1-136 (Seed RBAC Persistence Layer) merged via PR #15 commit 82f6dfe
 */
class TwoGateCheckTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_deprecated_feature_attempted_then_action_denied_fails_closed(): void
    {
        // Given a Free-tier company whose plan does NOT include the requested feature
        $company = Company::factory()->create(['current_billing_plan' => 'free']);

        // When canUseFeature is checked against a feature not in the plan
        $canUse = $company->canUseFeature(Feature::TwoFactorAuth);

        // Then the gate fails closed (deny by default for any feature not explicitly granted)
        $this->assertFalse($canUse, 'Feature not in plan must fail closed via canUseFeature.');
    }

    #[Test]
    public function when_billing_gate_fails_then_403_with_feature_unavailable(): void
    {
        // Given a Free-tier company attempting a Pro+ feature (2FA)
        $company = Company::factory()->create(['current_billing_plan' => 'free']);
        $user = User::factory()->create();
        $this->actingAs($user);

        // And a test route gated by feature:two_factor_auth (Pro+ per BillingPlan::Pro->features())
        Route::middleware(['auth', 'feature:two_factor_auth'])
            ->post('/_test/two-fa-setup', fn () => response()->json(['ok' => true]));

        // When the user attempts the gated action with the company context
        $response = $this->withHeaders(['X-Company-Id' => $company->id])
            ->postJson('/_test/two-fa-setup');

        // Then the response is 403 with FEATURE_NOT_AVAILABLE envelope + upgrade_to hint
        $this->assertGateBlockedAsFeatureUnavailable($response, expectedUpgradeTo: 'pro');
    }

    #[Test]
    public function when_role_gate_fails_then_403_with_role_insufficient(): void
    {
        // Given a Pro-tier company (billing gate passes for 2FA)
        $company = Company::factory()->create(['current_billing_plan' => 'pro']);
        // And a user whose role does NOT have the required permission
        $user = User::factory()->create();
        $employeeRole = Role::factory()
            ->create(['code' => 'employee']);
        $company->users()->attach($user, ['role_id' => $employeeRole->id]);
        $this->actingAs($user);

        // And a test route gated by feature:two_factor_auth + permission:manage_security
        Route::middleware(['auth', 'feature:two_factor_auth', 'permission:manage_security'])
            ->post('/_test/two-fa-policy', fn () => response()->json(['ok' => true]));

        // When the user attempts the action
        $response = $this->withHeaders(['X-Company-Id' => $company->id])
            ->postJson('/_test/two-fa-policy');

        // Then the response is 403 with ROLE_INSUFFICIENT envelope
        $this->assertGateBlockedAsRoleInsufficient($response, expectedUserRole: 'employee');
    }

    protected function assertGateBlockedAsFeatureUnavailable(
        TestResponse $response,
        string $expectedUpgradeTo
    ): void {
        $response->assertStatus(403);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('error_code', 'FEATURE_NOT_AVAILABLE');
        $response->assertJsonPath('upgrade_to', $expectedUpgradeTo);
    }

    protected function assertGateBlockedAsRoleInsufficient(
        TestResponse $response,
        ?string $expectedUserRole
    ): void {
        $response->assertStatus(403);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('error_code', 'ROLE_INSUFFICIENT');
        if ($expectedUserRole !== null) {
            $response->assertJsonPath('user_role', $expectedUserRole);
        }
    }
}
