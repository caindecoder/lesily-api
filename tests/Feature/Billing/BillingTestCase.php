<?php

namespace Tests\Feature\Billing;

use Tests\TestCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Billing\Subscriptions\UseCases\Commands\StartTrialCommand;
use App\Billing\Subscriptions\Entities\Subscription as SubscriptionReadModel;

abstract class BillingTestCase extends TestCase
{
    /**
     * Look up a seeded RBAC-catalog role by code — requires seedRbacCatalog() in
     * the calling suite's setUp. Duplicated from CompaniesTestCase/AuthTestCase
     * (module bases are independent — established precedent).
     */
    protected function seededRole(string $code): Role
    {
        return Role::query()->where('code', $code)->sole();
    }

    /**
     * A fresh user made a live member of $company under a seeded role — the
     * company_user pivot row the billing.view gate reads. Duplicated from
     * CompaniesTestCase (module bases are independent — established precedent).
     */
    protected function aCompanyMember(Company $company, string $roleCode = 'employee'): User
    {
        $user = User::factory()->create();

        $company->users()->attach($user, ['role_id' => $this->seededRole($roleCode)->getKey()]);

        return $user;
    }

    /**
     * A company on a paid plan with one fresh member under a seeded role.
     *
     * @return array{0: Company, 1: User}
     */
    protected function aCompanyWithMember(string $roleCode): array
    {
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();

        return [$company, $this->aCompanyMember($company, $roleCode)];
    }

    /**
     * The plan catalog came back — 200 with all four standard tiers in the
     * non-paginated {data: [...]} envelope, each row carrying the six contract
     * keys (LD assertJsonCount shape-lock pattern).
     */
    protected function assertCatalogReturned(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertJsonCount(4, 'data');
        $response->assertJsonStructure(['data' => ['*' => [
            'code', 'label', 'features', 'monthly_price_cents', 'annual_price_cents', 'currency',
        ]]]);
    }

    /**
     * An authenticated member requests the plan catalog under $company's context.
     */
    protected function viewPlansAs(User $user, Company $company): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/plans', ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * An authenticated member requests the company's subscription state under
     * $company's context.
     */
    protected function viewSubscriptionAs(User $user, Company $company): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/subscription', ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * A guest (no token) requests the company's subscription state.
     */
    protected function viewSubscriptionAsGuest(): TestResponse
    {
        return $this->getJson('/api/v1/subscription');
    }

    /**
     * The request was rejected as unauthenticated — 401 with the §S21
     * UNAUTHENTICATED envelope. Duplicated from CompaniesTestCase/AuthTestCase
     * (module bases are independent — established precedent; base-level
     * consolidation stays a flagged future refactor).
     */
    protected function assertUnauthenticated(TestResponse $response): void
    {
        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * The member's role lacks the required permission — 403 ROLE_INSUFFICIENT
     * naming the missing grant. Duplicated from CompaniesTestCase (module bases
     * are independent — established precedent).
     */
    protected function assertRoleInsufficient(TestResponse $response, string $permission): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'ROLE_INSUFFICIENT');
        $response->assertJsonPath('required_permission', $permission);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * A trial subscription projected for $company through the domain seam —
     * the genesis event path production takes (SubscriptionProjectionTest
     * precedent), never a direct row write.
     */
    protected function aTrialSubscriptionFor(Company $company, CarbonImmutable $trialEndsAt): SubscriptionReadModel
    {
        $subscriptionId = (string) Str::uuid();

        Subscription::retrieve($subscriptionId)
            ->startTrial(StartTrialCommand::validateAndCreate([
                'companyId' => $company->getKey(),
                'trialEndsAt' => $trialEndsAt,
            ]))
            ->persist();

        return SubscriptionReadModel::findOrFail($subscriptionId);
    }

    /**
     * A subscription whose trial ran out — started and ended through the domain
     * seam, exactly as the trials:expire scan does (ended_source 'trial-expired',
     * cancelled_at stamped from the travelled clock).
     */
    protected function anEndedTrialSubscriptionFor(Company $company, CarbonImmutable $trialEndsAt): SubscriptionReadModel
    {
        $subscription = $this->aTrialSubscriptionFor($company, $trialEndsAt);

        Subscription::retrieve($subscription->getKey())
            ->endTrial('trial-expired')
            ->persist();

        return $subscription->refresh();
    }

    /**
     * The subscription state matches the designed 13-key contract exactly —
     * payload identity against literal expectations, so nothing extra can leak
     * and every documented-null key is proven present (B5).
     */
    protected function assertExactSubscriptionContract(TestResponse $response, array $expected): void
    {
        $response->assertOk();
        $response->assertExactJson(['data' => $expected]);
    }

    /**
     * The subscription state came back — 200 with exactly the 13 designed
     * contract keys in the {data: {...}} envelope (LD assertJsonCount
     * shape-lock pattern).
     */
    protected function assertSubscriptionShapeReturned(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertJsonCount(13, 'data');
        $response->assertJsonStructure(['data' => [
            'id', 'status', 'plan', 'trial_ends_at', 'add_ons', 'cancelled_at', 'ended_source',
            'billing_cycle', 'period_start', 'period_end', 'next_charge_at',
            'payment_method_summary', 'past_due_status',
        ]]);
    }

    /**
     * The roles the 2026-05-24 matrix grants billing.view (owner ✅, admin ✅,
     * auditor 👁️ read-only) — shared by every billing.view-gated read (§2.12).
     *
     * @return array<string, array{string}>
     */
    public static function provideViewingRoles(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
            'auditor' => ['auditor'],
        ];
    }

    /**
     * The seeded roles the 2026-05-24 matrix denies billing.view.
     *
     * @return array<string, array{string}>
     */
    public static function provideDeniedRoles(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'manager' => ['manager'],
        ];
    }
}
