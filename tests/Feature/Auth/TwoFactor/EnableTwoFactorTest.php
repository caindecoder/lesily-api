<?php

namespace Tests\Feature\Auth\TwoFactor;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\WithRolePermissionFixtures;

class EnableTwoFactorTest extends AuthTestCase
{
    use RefreshDatabase;
    use WithRolePermissionFixtures;

    #[Test]
    #[DataProvider('provideSubProPlans')]
    public function when_a_sub_pro_user_starts_enrollment_then_two_factor_is_unavailable(BillingPlan $plan): void
    {
        // Given (a member of a company whose plan does not grant 2FA)
        $company = Company::factory()->onPlan($plan)->create();
        $user = $this->aMemberOf($company);

        // When the member starts 2FA enrollment
        $response = $this->enable($user, $company);

        // Then enrollment is refused and no secret is staged
        $this->assertFeatureUnavailable($response, $user);
    }

    #[Test]
    public function when_enrollment_is_confirmed_without_being_started_then_it_is_rejected(): void
    {
        // Given (a Pro member who never started enrollment — no staged secret)
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = $this->aMemberOf($company);

        // When they submit a confirmation code
        $response = $this->confirm($user, $company, '123456');

        // Then it is rejected and nothing is enabled
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'TWO_FACTOR_SETUP_NOT_STARTED');
        $this->assertNoTwoFactorPersisted($user);
    }

    /**
     * @return array<string, array{BillingPlan}>
     */
    public static function provideSubProPlans(): array
    {
        return [
            'free' => [BillingPlan::Free],
            'starter' => [BillingPlan::Starter],
        ];
    }

    protected function aMemberOf(Company $company): User
    {
        $user = User::factory()->create();
        $this->joinCompany($company, $user);

        return $user;
    }

    protected function enable(User $user, Company $company): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson(
            '/api/v1/auth/2fa/enable',
            [],
            ['X-Company-Context' => $company->getKey()],
        );
    }

    protected function confirm(User $user, Company $company, string $code): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson(
            '/api/v1/auth/2fa/confirm',
            ['code' => $code],
            ['X-Company-Context' => $company->getKey()],
        );
    }

    protected function assertFeatureUnavailable(TestResponse $response, User $user): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'FEATURE_NOT_AVAILABLE');
        $response->assertJsonPath('upgrade_to', 'Pro');
        $this->assertNull(Cache::get("2fa-setup:{$user->id}"), 'A blocked enrollment must stage no pending secret.');
    }

    protected function assertNoTwoFactorPersisted(User $user): void
    {
        $this->assertDatabaseHas('users', [
            'id' => $user->getKey(),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }
}
