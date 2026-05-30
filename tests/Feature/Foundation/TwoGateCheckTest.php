<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TwoGateCheckTest extends FoundationTestCase
{
    use RefreshDatabase;
    use WithRolePermissionFixtures;

    #[Test]
    #[DataProvider('provideUnderTieredFeatures')]
    public function when_a_feature_is_outside_the_companys_plan_then_the_minimum_upgrade_tier_is_returned(
        string $route,
        BillingPlan $plan,
        string $expectedTier,
    ): void {
        // Given
        $company = Company::factory()->onPlan($plan)->create();
        $user = User::factory()->create();

        // When
        $response = $this->attemptGatedAction($route, $user, $company);

        // Then
        $this->assertFeatureUpgradePromptReturned($response, $expectedTier);
    }

    #[Test]
    public function when_a_members_role_lacks_the_required_permission_then_a_permission_denial_is_returned(): void
    {
        // Given
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = User::factory()->create();
        $role = Role::factory()->code('member')->withPermissions([])->create();
        $this->attachMember($company, $user, $role);

        // When
        $response = $this->attemptGatedAction('two-gate/secured', $user, $company);

        // Then
        $this->assertPermissionDenied($response, 'manage_security');
    }

    /**
     * @return array<string, array{string, BillingPlan, string}>
     */
    public static function provideUnderTieredFeatures(): array
    {
        return [
            'free company, starter-tier feature' => ['two-gate/starter-feature', BillingPlan::Free, 'Starter'],
            'free company, pro-tier feature' => ['two-gate/pro-feature', BillingPlan::Free, 'Pro'],
            'starter company, pro-tier feature' => ['two-gate/pro-feature', BillingPlan::Starter, 'Pro'],
        ];
    }

    protected function assertFeatureUpgradePromptReturned(TestResponse $response, string $tier): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'FEATURE_NOT_AVAILABLE');
        $response->assertJsonPath('upgrade_to', $tier);
    }

    protected function assertPermissionDenied(TestResponse $response, string $permission): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'ROLE_INSUFFICIENT');
        $response->assertJsonPath('required_permission', $permission);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }
}
