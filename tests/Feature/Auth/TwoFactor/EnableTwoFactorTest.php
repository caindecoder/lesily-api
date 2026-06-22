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

    protected function assertFeatureUnavailable(TestResponse $response, User $user): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'FEATURE_NOT_AVAILABLE');
        $response->assertJsonPath('upgrade_to', 'Pro');
        $this->assertNull(Cache::get("2fa-setup:{$user->id}"), 'A blocked enrollment must stage no pending secret.');
    }
}
