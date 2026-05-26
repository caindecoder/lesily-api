<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;
use App\Models\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\Feature;
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
}
