<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\Addon;
use Illuminate\Log\Events\MessageLogged;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\RolesPermissions\Entities\Role;

class TwoGateCheckTest extends FoundationTestCase
{
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
        $this->joinCompany($company, $user);

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
    public function when_the_authenticated_user_is_not_a_member_of_the_requested_company_then_company_access_is_denied(): void
    {
        // Given
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = User::factory()->create();

        // When (the user is authenticated but never joined the company)
        $response = $this->attemptGatedAction('two-gate/secured', $user, $company);

        // Then (denied upstream by AuthorizeCompany — the gates are never reached)
        $this->assertCompanyAccessDenied($response);
    }

    #[Test]
    public function when_a_companys_trial_has_expired_then_a_plan_gated_feature_is_denied(): void
    {
        // Given a company whose trial ended without a plan being chosen
        $company = Company::factory()->trialExpired()->create();
        $user = User::factory()->create();
        $this->joinCompany($company, $user);

        // When it attempts a plan-gated feature
        $response = $this->attemptGatedAction('two-gate/starter-feature', $user, $company);

        // Then the billing gate denies it, pointing at the lowest plan that grants it
        $this->assertFeatureUpgradePromptReturned($response, 'Starter');
    }

    #[Test]
    public function when_a_feature_is_offered_by_no_plan_or_addon_then_the_gate_fails_closed(): void
    {
        // Given (a deprecated feature granted by no plan or add-on, even on the top tier)
        $company = Company::factory()->onPlan(BillingPlan::Enterprise)->create();
        $user = User::factory()->create();
        $this->joinCompany($company, $user);
        $loggedContext = null;
        Log::listen(function (MessageLogged $log) use (&$loggedContext): void {
            if ($log->level === 'warning') {
                $loggedContext = $log->context;
            }
        });

        // When
        $response = $this->attemptGatedAction('two-gate/deprecated', $user, $company);

        // Then
        $this->assertFailsClosed($response);
        $this->assertSame(['feature' => 'legacy_import', 'company_id' => $company->getKey()], $loggedContext);
    }

    #[Test]
    #[DataProvider('provideFeaturesNoPlanGrants')]
    public function when_a_trial_company_asks_for_a_feature_no_plan_grants_then_the_gate_fails_closed(string $route): void
    {
        // Given a company on its trial that has bought no add-on
        $company = Company::factory()->onTrial()->create();
        $user = User::factory()->create();
        $this->joinCompany($company, $user);

        // When it asks for a feature that no plan grants
        $response = $this->attemptGatedAction($route, $user, $company);

        // Then the trial does not open it
        $this->assertFailsClosed($response);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideFeaturesNoPlanGrants(): array
    {
        return [
            'add-on-only, not bought' => ['two-gate/templates'],
            'deprecated' => ['two-gate/deprecated'],
        ];
    }

    #[Test]
    public function when_a_plan_less_company_has_an_addon_granting_a_feature_then_access_is_allowed(): void
    {
        // Given (a company with no plan in force — its trial expired — that holds the Template Engine add-on)
        $company = Company::factory()->trialExpired()->withAddon(Addon::TemplateEngine)->create();
        $user = User::factory()->create();
        $this->joinCompany($company, $user);

        // When it attempts the add-on-granted feature
        $response = $this->attemptGatedAction('two-gate/templates', $user, $company);

        // Then access is allowed — the absent plan must not short-circuit the add-on path
        $this->assertGatedActionProceeded($response);
    }

    #[Test]
    public function when_a_member_of_a_free_and_a_pro_company_acts_in_the_free_one_then_the_upgrade_prompt_is_returned(): void
    {
        // Given one member, one role, in a Free company and a Pro company
        $user = User::factory()->create();
        [$freeCompany] = $this->memberOfAFreeAndAProCompany($user);

        // When the member attempts a Pro feature in the Free company's context
        $response = $this->attemptGatedAction('two-gate/secured', $user, $freeCompany);

        // Then that company's plan decides, not the user's other membership
        $this->assertFeatureUpgradePromptReturned($response, 'Pro');
    }

    #[Test]
    public function when_a_member_of_a_free_and_a_pro_company_acts_in_the_pro_one_then_access_proceeds(): void
    {
        // Given one member, one role, in a Free company and a Pro company
        $user = User::factory()->create();
        [, $proCompany] = $this->memberOfAFreeAndAProCompany($user);

        // When the member attempts a Pro feature in the Pro company's context
        $response = $this->attemptGatedAction('two-gate/secured', $user, $proCompany);

        // Then that company's plan decides, not the user's other membership
        $this->assertGatedActionProceeded($response);
    }

    #[Test]
    #[DataProvider('provideGrantedFeatures')]
    public function when_a_companys_plan_grants_a_feature_then_access_proceeds(string $route, BillingPlan $plan): void
    {
        // Given (each tier grants its own features and inherits every lower tier's)
        $company = Company::factory()->onPlan($plan)->create();
        $user = User::factory()->create();
        $this->joinCompany($company, $user);

        // When
        $response = $this->attemptGatedAction($route, $user, $company);

        // Then
        $this->assertGatedActionProceeded($response);
    }

    /**
     * @return array<string, array{string, BillingPlan}>
     */
    public static function provideGrantedFeatures(): array
    {
        return [
            'starter grants its own feature' => ['two-gate/starter-feature', BillingPlan::Starter],
            'pro inherits the starter feature' => ['two-gate/starter-feature', BillingPlan::Pro],
            'pro grants its own feature' => ['two-gate/pro-feature', BillingPlan::Pro],
            'enterprise inherits the starter feature' => ['two-gate/starter-feature', BillingPlan::Enterprise],
            'enterprise inherits the pro feature' => ['two-gate/pro-feature', BillingPlan::Enterprise],
        ];
    }

    #[Test]
    #[DataProvider('provideTrialGrantedFeatures')]
    public function when_a_company_is_on_trial_then_every_plan_feature_is_granted(string $route): void
    {
        // Given a company on its trial, no plan chosen yet
        $company = Company::factory()->onTrial()->create();
        $user = User::factory()->create();
        $this->joinCompany($company, $user);

        // When it asks for a plan-gated feature
        $response = $this->attemptGatedAction($route, $user, $company);

        // Then the trial grants it, as the top plan would
        $this->assertGatedActionProceeded($response);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideTrialGrantedFeatures(): array
    {
        return [
            'the starter-tier feature' => ['two-gate/starter-feature'],
            'the pro-tier feature' => ['two-gate/pro-feature'],
        ];
    }

    #[Test]
    public function when_an_addon_grants_a_feature_the_plan_excludes_then_access_is_allowed(): void
    {
        // Given (Free plan lacks TemplateEngine, but the Template Engine add-on grants it)
        $company = Company::factory()->onPlan(BillingPlan::Free)->withAddon(Addon::TemplateEngine)->create();
        $user = User::factory()->create();
        $this->joinCompany($company, $user);

        // When
        $response = $this->attemptGatedAction('two-gate/templates', $user, $company);

        // Then
        $this->assertGatedActionProceeded($response);
    }

    #[Test]
    public function when_both_gates_pass_then_the_gated_action_proceeds(): void
    {
        // Given (Pro company + member whose role grants manage_security)
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = User::factory()->create();
        $role = Role::factory()->code('admin')->withPermissions(['manage_security'])->create();
        $this->attachMember($company, $user, $role);

        // When
        $response = $this->attemptGatedAction('two-gate/secured', $user, $company);

        // Then
        $this->assertGatedActionProceeded($response);
    }

    protected function assertFeatureUpgradePromptReturned(TestResponse $response, string $tier): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'FEATURE_NOT_AVAILABLE');
        $response->assertJsonPath('upgrade_to', $tier);
        $response->assertJsonPath('upgrade_url', '/settings/billing/upgrade');
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

    /**
     * @return array{Company, Company}
     */
    protected function memberOfAFreeAndAProCompany(User $user): array
    {
        $role = Role::factory()->code('member')->withPermissions(['manage_security'])->create();
        $freeCompany = Company::factory()->onPlan(BillingPlan::Free)->create();
        $proCompany = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $this->attachMember($freeCompany, $user, $role);
        $this->attachMember($proCompany, $user, $role);

        return [$freeCompany, $proCompany];
    }
}
