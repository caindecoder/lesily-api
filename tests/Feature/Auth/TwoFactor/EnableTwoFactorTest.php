<?php

namespace Tests\Feature\Auth\TwoFactor;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Auth\Totp\TotpVerifier;
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

    #[Test]
    public function when_enrollment_is_confirmed_with_an_invalid_code_then_nothing_is_persisted(): void
    {
        // Given (a Pro member mid-enrollment whose authenticator code will not verify)
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = $this->aMemberOf($company);
        $this->fakeTotpVerifier(verifies: false);
        $this->enable($user, $company);

        // When they submit a code the authenticator does not accept
        $response = $this->confirm($user, $company, '000000');

        // Then it is rejected and 2FA stays disabled
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'INVALID_TWO_FACTOR_CODE');
        $this->assertNoTwoFactorPersisted($user);
    }

    #[Test]
    public function when_enrollment_starts_then_a_provisioning_uri_and_secret_are_returned_without_persisting(): void
    {
        // Given (a Pro member who has not yet enabled 2FA)
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = $this->aMemberOf($company);
        $this->fakeTotpVerifier();

        // When they start enrollment
        $response = $this->enable($user, $company);

        // Then a TOTP secret + provisioning URI are returned, but nothing is persisted yet
        $this->assertProvisioningReturnedWithoutPersisting($response, $user);
    }

    #[Test]
    public function when_a_pro_user_completes_enrollment_then_two_factor_is_enabled(): void
    {
        // Given (a Pro member mid-enrollment whose authenticator code verifies)
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = $this->aMemberOf($company);
        $this->fakeTotpVerifier(verifies: true);
        $stagedSecret = (string) $this->enable($user, $company)->json('data.secret');

        // When they confirm with a valid code
        $response = $this->confirm($user, $company, '123456');

        // Then 2FA is enabled and the secret is stored encrypted
        $this->assertTwoFactorEnabled($response, $user, $stagedSecret);
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

    /**
     * Bind a deterministic TotpVerifier double — the only mocked boundary (E23). It
     * stages a runtime-generated secret (no literal — SonarCloud) and lets the test
     * decide whether a submitted code verifies.
     */
    protected function fakeTotpVerifier(bool $verifies = true): void
    {
        $this->app->instance(TotpVerifier::class, new class($verifies) implements TotpVerifier
        {
            public function __construct(private bool $verifies) {}

            public function generateSecret(): string
            {
                return 'STAGED'.Str::upper(Str::random(10));
            }

            public function provisioningUri(string $secret, string $accountName): string
            {
                return "otpauth://totp/Lesily:{$accountName}?secret={$secret}&issuer=Lesily";
            }

            public function verify(string $secret, string $code): bool
            {
                return $this->verifies;
            }
        });
    }

    protected function assertFeatureUnavailable(TestResponse $response, User $user): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'FEATURE_NOT_AVAILABLE');
        $response->assertJsonPath('upgrade_to', 'Pro');
        $this->assertNull(Cache::get("2fa-setup:{$user->id}"), 'A blocked enrollment must stage no pending secret.');
    }

    protected function assertProvisioningReturnedWithoutPersisting(TestResponse $response, User $user): void
    {
        $response->assertOk();
        $secret = (string) $response->json('data.secret');
        $uri = (string) $response->json('data.otpauth_uri');
        $this->assertNotEmpty($secret, 'Enrollment must return a TOTP secret to provision the authenticator.');
        $this->assertStringStartsWith('otpauth://', $uri);
        $this->assertStringContainsString($secret, $uri);
        $this->assertNoTwoFactorPersisted($user);
    }

    protected function assertTwoFactorEnabled(TestResponse $response, User $user, string $stagedSecret): void
    {
        $response->assertOk();
        $reloaded = User::findOrFail($user->getKey());
        $this->assertNotNull($reloaded->two_factor_confirmed_at, '2FA must be marked enabled at confirm.');
        $this->assertSame($stagedSecret, $reloaded->two_factor_secret, 'The stored secret must decrypt to the staged secret.');
        $this->assertNotEmpty($response->json('data.recovery_codes'), 'Recovery codes are shown once at confirm.');
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
