<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
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
}
