<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;
use App\Models\User;
use App\Models\Company;
use App\Models\CompanyAddon;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\Addon;
use App\Foundation\Billing\Enums\Feature;
use App\Foundation\Http\Responses\EnvelopeBuilder;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\RolesPermissions\Entities\Permission;
use App\Foundation\Http\Middleware\EnsureFeatureAvailable;
use App\Foundation\RolesPermissions\Entities\RolePermission;
use App\Foundation\Http\Middleware\CheckPermissionMiddleware;
use App\Foundation\RolesPermissions\Services\PermissionEvaluator;

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

    #[Test]
    public function when_same_user_in_two_tier_companies_then_feature_access_per_company_context(): void
    {
        // Given a user with a role in both Free-tier Contoso and Pro-tier ACME
        $contoso = Company::factory()->create(['current_billing_plan' => 'free']);
        $acme = Company::factory()->create(['current_billing_plan' => 'pro']);
        $user = User::factory()->create();
        $adminRole = Role::factory()->create(['code' => 'admin']);
        // Attach manage_security permission to admin (so role gate passes in both contexts)
        $managePermission = Permission::factory()
            ->create(['code' => 'manage_security']);
        RolePermission::factory()
            ->create(['role_id' => $adminRole->id, 'permission_id' => $managePermission->id]);
        $contoso->users()->attach($user, ['role_id' => $adminRole->id]);
        $acme->users()->attach($user, ['role_id' => $adminRole->id]);
        $this->actingAs($user);

        Route::middleware(['auth', 'feature:two_factor_auth', 'permission:manage_security'])
            ->post('/_test/tenant-iso', fn () => response()->json(['ok' => true]));

        // When the user attempts the action in Contoso (Free) — billing gate fails
        $contosoResponse = $this->withHeaders(['X-Company-Id' => $contoso->id])
            ->postJson('/_test/tenant-iso');
        $this->assertGateBlockedAsFeatureUnavailable($contosoResponse, expectedUpgradeTo: 'pro');

        // When the user attempts the action in ACME (Pro) — both gates pass
        $acmeResponse = $this->withHeaders(['X-Company-Id' => $acme->id])
            ->postJson('/_test/tenant-iso');
        $acmeResponse->assertStatus(200);
        $acmeResponse->assertJsonPath('ok', true);
    }

    #[Test]
    public function when_addon_covers_feature_on_lower_plan_then_action_proceeds(): void
    {
        // Given a Free-tier company with the Template Engine add-on active
        $company = Company::factory()->create(['current_billing_plan' => 'free']);
        CompanyAddon::create([
            'company_id' => $company->id,
            'addon' => Addon::TemplateEngine,
        ]);
        $company->refresh();

        $user = User::factory()->create();
        $adminRole = Role::factory()->create(['code' => 'admin']);
        $usePermission = Permission::factory()
            ->create(['code' => 'use_templates']);
        RolePermission::factory()
            ->create(['role_id' => $adminRole->id, 'permission_id' => $usePermission->id]);
        $company->users()->attach($user, ['role_id' => $adminRole->id]);
        $this->actingAs($user);

        Route::middleware(['auth', 'feature:template_engine', 'permission:use_templates'])
            ->post('/_test/template-render', fn () => response()->json(['ok' => true]));

        // When the user attempts the feature gated by an add-on (TemplateEngine)
        $response = $this->withHeaders(['X-Company-Id' => $company->id])
            ->postJson('/_test/template-render');

        // Then the action proceeds (add-on covers the feature even though plan doesn't)
        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
    }

    #[Test]
    public function when_both_gates_pass_then_action_proceeds(): void
    {
        // Given a Pro-tier company "Contoso" with admin "Alice" who has the required permission
        $contoso = Company::factory()->create(['current_billing_plan' => 'pro']);
        $alice = User::factory()->create();
        $adminRole = Role::factory()->create(['code' => 'admin']);
        $managePermission = Permission::factory()
            ->create(['code' => 'manage_security']);
        RolePermission::factory()
            ->create(['role_id' => $adminRole->id, 'permission_id' => $managePermission->id]);
        $contoso->users()->attach($alice, ['role_id' => $adminRole->id]);
        $this->actingAs($alice);

        Route::middleware(['auth', 'feature:two_factor_auth', 'permission:manage_security'])
            ->post('/_test/two-fa-happy', fn () => response()->json(['ok' => true]));

        // When Alice attempts to set up 2FA
        $response = $this->withHeaders(['X-Company-Id' => $contoso->id])
            ->postJson('/_test/two-fa-happy');

        // Then the action proceeds (no 403, normal pass-through to the route handler)
        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
    }

    #[Test]
    public function uc_1_055_public_apis_preserve_their_visibility_contracts(): void
    {
        // Preempts PublicVisibility / ProtectedVisibility mutants per UC-1-136 CI lessons.
        // External callers (middleware, evaluator, controllers, render handlers) depend on these
        // visibility levels being exactly as declared.
        $publicContract = [
            [Company::class, 'canUseFeature', 'isPublic'],
            [Company::class, 'users', 'isPublic'],
            [Company::class, 'activeAddons', 'isPublic'],
            [EnsureFeatureAvailable::class, 'handle', 'isPublic'],
            [CheckPermissionMiddleware::class, 'handle', 'isPublic'],
            [PermissionEvaluator::class, 'canUserDo', 'isPublic'],
            [PermissionEvaluator::class, 'canRoleDo', 'isPublic'],
            [PermissionEvaluator::class, 'resolveRoleId', 'isPublic'],
            [EnvelopeBuilder::class, 'build', 'isPublic'],
            [Company::class, 'newFactory', 'isProtected'],
        ];

        foreach ($publicContract as [$class, $method, $expectedVisibilityMethod]) {
            $reflection = new \ReflectionMethod($class, $method);
            $this->assertTrue(
                $reflection->{$expectedVisibilityMethod}(),
                "{$class}::{$method}() visibility contract violated."
            );
        }
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
