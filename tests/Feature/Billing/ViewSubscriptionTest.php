<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\Addon;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ViewSubscriptionTest extends BillingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();

        config(['app.debug' => false]);
    }

    // Sad — the read is token-gated: a guest gets the §S21 UNAUTHENTICATED envelope.
    #[Test]
    public function when_guest_requests_subscription_then_unauthenticated(): void
    {
        // Given no authenticated user

        // When the subscription is requested without a token
        $response = $this->viewSubscriptionAsGuest();

        // Then the request is rejected as unauthenticated
        $this->assertUnauthenticated($response);
    }

    // Sad — the read is billing.view-gated: a member whose seeded role lacks the
    // grant is refused without any billing field in the body (UC-1-041 error 1a;
    // 2026-05-24 matrix ❌ rows).
    #[Test]
    #[DataProvider('provideDeniedRoles')]
    public function when_role_lacks_billing_view_then_subscription_is_refused(string $roleCode): void
    {
        // Given a company member whose role lacks billing.view
        [$company, $member] = $this->aCompanyWithMember($roleCode);

        // When they request the subscription state
        $response = $this->viewSubscriptionAs($member, $company);

        // Then the request is refused naming the missing grant
        $this->assertRoleInsufficient($response, 'billing.view');
    }

    // Sad — the company-scope gate runs ahead of the permission gate: an owner of
    // Company A probing under Company B's context is refused as a non-member,
    // regardless of their own role's grants (UC-1-041 tenant isolation, sad side).
    #[Test]
    public function when_company_context_is_foreign_then_access_is_denied(): void
    {
        // Given an owner of Company A and a foreign Company B
        [, $owner] = $this->aCompanyWithMember('owner');
        [$foreignCompany] = $this->aCompanyWithMember('owner');

        // When they request the subscription state under Company B's context
        $response = $this->viewSubscriptionAs($owner, $foreignCompany);

        // Then company access is denied
        $this->assertCompanyAccessDenied($response);
    }

    // Edge — the trial company (the endpoint's Day-31 primary consumer): plan is
    // NULL until a plan is chosen (s066 fail-closed ruling), the countdown date is
    // exact, and every payment-slice key is present as a documented null (B5).
    #[Test]
    public function when_company_is_in_trial_then_plan_is_null_with_trial_countdown(): void
    {
        // Given an owner of a trial company, 30 days from expiry, no plan chosen
        $this->travelTo('2026-06-01 00:00:00');
        $company = Company::factory()->withoutPlan()->create();
        $owner = $this->aCompanyMember($company, 'owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));

        // When they request the subscription state
        $response = $this->viewSubscriptionAs($owner, $company);

        // Then the trial state is reported with a null plan and the exact countdown
        $this->assertExactSubscriptionContract($response, [
            'id' => $subscription->getKey(),
            'status' => 'trial',
            'plan' => null,
            'trial_ends_at' => '2026-07-01T00:00:00.000000Z',
            'add_ons' => [],
            'cancelled_at' => null,
            'ended_source' => null,
            'billing_cycle' => null,
            'period_start' => null,
            'period_end' => null,
            'next_charge_at' => null,
            'payment_method_summary' => null,
            'past_due_status' => null,
        ]);
    }

    // Edge — the post-trial Free company (§10 switch-to-Free path): the plan reads
    // 'free' while the subscription row keeps the expired trial's cancellation
    // record — the only state giving ended_source/cancelled_at real values as-built.
    #[Test]
    public function when_company_settled_on_free_after_trial_then_cancellation_is_reported(): void
    {
        // Given an owner of a Free-plan company whose trial expired yesterday
        $this->travelTo('2026-06-01 00:00:00');
        $company = Company::factory()->onPlan(BillingPlan::Free)->create();
        $owner = $this->aCompanyMember($company, 'owner');
        $subscription = $this->anEndedTrialSubscriptionFor($company, CarbonImmutable::now()->subDay());

        // When they request the subscription state
        $response = $this->viewSubscriptionAs($owner, $company);

        // Then the Free plan is reported alongside the expired trial's cancellation
        $this->assertExactSubscriptionContract($response, [
            'id' => $subscription->getKey(),
            'status' => 'cancelled',
            'plan' => 'free',
            'trial_ends_at' => '2026-05-31T00:00:00.000000Z',
            'add_ons' => [],
            'cancelled_at' => '2026-06-01T00:00:00.000000Z',
            'ended_source' => 'trial-expired',
            'billing_cycle' => null,
            'period_start' => null,
            'period_end' => null,
            'next_charge_at' => null,
            'payment_method_summary' => null,
            'past_due_status' => null,
        ]);
    }

    // Happy — every role the 2026-05-24 matrix grants billing.view (owner ✅,
    // admin ✅ view-only, auditor 👁️ read-only — R2 s129) reads the same
    // 13-key subscription shape.
    #[Test]
    #[DataProvider('provideViewingRoles')]
    public function when_viewing_role_requests_subscription_then_billing_state_is_returned(string $roleCode): void
    {
        // Given a company member whose seeded role holds billing.view
        [$company, $member] = $this->aCompanyWithMember($roleCode);
        $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(30));

        // When they request the subscription state
        $response = $this->viewSubscriptionAs($member, $company);

        // Then the full 13-key contract shape is returned
        $this->assertSubscriptionShapeReturned($response);
    }

    // Happy — the exact designed contract on a paid company: plan code, active
    // add-on codes from company_addons, the trial row's live values, and every
    // payment-slice key pinned to its documented null (B5) — payload identity,
    // so no PayHere-to-be field or admin-only column can ever leak (E21).
    #[Test]
    public function when_owner_views_pro_company_with_addon_then_full_billing_contract_returned(): void
    {
        // Given an owner of a Pro company holding the Template Engine add-on, mid-trial
        $this->travelTo('2026-06-01 00:00:00');
        $company = Company::factory()->onPlan(BillingPlan::Pro)->withAddon(Addon::TemplateEngine)->create();
        $owner = $this->aCompanyMember($company, 'owner');
        $subscription = $this->aTrialSubscriptionFor($company, CarbonImmutable::now()->addDays(12));

        // When they request the subscription state
        $response = $this->viewSubscriptionAs($owner, $company);

        // Then the exact 13-key contract is returned
        $this->assertExactSubscriptionContract($response, [
            'id' => $subscription->getKey(),
            'status' => 'trial',
            'plan' => 'pro',
            'trial_ends_at' => '2026-06-13T00:00:00.000000Z',
            'add_ons' => ['template_engine'],
            'cancelled_at' => null,
            'ended_source' => null,
            'billing_cycle' => null,
            'period_start' => null,
            'period_end' => null,
            'next_charge_at' => null,
            'payment_method_summary' => null,
            'past_due_status' => null,
        ]);
    }
}
