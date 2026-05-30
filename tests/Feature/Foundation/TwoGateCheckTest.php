<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Log;
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

    #[Test]
    public function when_a_member_has_no_role_in_the_company_then_the_permission_gate_denies(): void
    {
        // Given
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = User::factory()->create();

        // When (the user is authenticated but never joined the company)
        $response = $this->attemptGatedAction('two-gate/secured', $user, $company);

        // Then
        $this->assertPermissionDenied($response, 'manage_security');
    }

    #[Test]
    public function when_a_feature_is_offered_by_no_plan_or_addon_then_the_gate_fails_closed(): void
    {
        // Given (a deprecated feature granted by no plan or add-on, even on the top tier)
        $logSpy = Log::spy();
        $company = Company::factory()->onPlan(BillingPlan::Enterprise)->create();
        $user = User::factory()->create();

        // When
        $response = $this->attemptGatedAction('two-gate/deprecated', $user, $company);

        // Then
        $this->assertFailsClosed($response);
        $logSpy->shouldHaveReceived('warning');
    }

    #[Test]
    public function when_a_higher_tier_company_uses_a_lower_tier_feature_then_access_is_inherited(): void
    {
        // Given (Pro inherits Starter's EmployeeDirectory via the features() spread)
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = User::factory()->create();

        // When
        $response = $this->attemptGatedAction('two-gate/starter-feature', $user, $company);

        // Then
        $this->assertGatedActionProceeded($response);
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

    protected function assertFailsClosed(TestResponse $response): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'FEATURE_NOT_AVAILABLE');
        $response->assertJsonMissingPath('upgrade_to');
    }

    protected function assertGatedActionProceeded(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertJsonPath('ok', true);
    }
}
