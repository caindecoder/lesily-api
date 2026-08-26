<?php

namespace Tests\Feature\Auth\TwoFactor;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Foundation\Auth\Events\User2FAEnabled;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\WithRolePermissionFixtures;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class EnableTwoFactorTest extends AuthTestCase
{
    use RefreshDatabase;
    use WithRolePermissionFixtures;

    #[Test]
    public function when_enrollment_is_confirmed_without_a_code_then_the_request_is_rejected_as_invalid(): void
    {
        // Given (a Pro member mid-enrollment)
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = $this->aMemberOf($company);
        $this->fakeTotpVerifier(verifies: true);
        $this->enable($user, $company);

        // When they confirm with no code at all
        $response = $this->confirmWith($user, $company, []);

        // Then the request is rejected as a validation error naming the code
        $this->assertValidationRejected($response, 'code', 'The code field is required.');
    }

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
    public function when_an_enrolled_user_starts_enrollment_again_then_it_is_rejected(): void
    {
        // Given (a Pro member who already has 2FA enabled)
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = User::factory()->withTwoFactor()->create();
        $this->joinCompany($company, $user);

        // When they start enrollment a second time
        $response = $this->enable($user, $company);

        // Then it is refused as a conflict and no new secret is staged
        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'TWO_FACTOR_ALREADY_ENABLED');
        $this->assertNull(Cache::get("2fa-setup:{$user->id}"), 'A rejected re-enrollment must stage no pending secret.');
    }

    // Sad — 2FA confirm is idempotent on the aggregate: re-confirming records no second marker.
    #[Test]
    public function when_two_factor_is_already_confirmed_then_re_confirming_records_no_further_change(): void
    {
        // Given a user who has already confirmed 2FA
        $userId = (string) Str::uuid();
        UserAggregate::retrieve($userId)->confirmTwoFactor()->persist();

        // When 2FA is confirmed again
        UserAggregate::retrieve($userId)->confirmTwoFactor()->persist();

        // Then only one enable marker was ever recorded
        $this->assertSame(1, LesilyStoredEvent::query()->where('event_class', User2FAEnabled::class)->count());
    }

    #[Test]
    public function when_confirmation_attempts_exceed_the_limit_then_they_are_throttled(): void
    {
        // Given (an authenticated Pro member)
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = $this->aMemberOf($company);

        // When confirm is called past the per-IP auth-tier allowance (the TOTP brute-force surface)
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->confirm($user, $company, '123456');
        }
        $response = $this->confirm($user, $company, '123456');

        // Then the over-limit attempt is throttled
        $this->assertThrottled($response);
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
    public function when_enrollment_is_confirmed_then_ten_unique_recovery_codes_are_returned_hashed(): void
    {
        // Given (a Pro member completing enrollment)
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = $this->aMemberOf($company);
        $this->fakeTotpVerifier(verifies: true);
        $this->enable($user, $company);

        // When they confirm with a valid code
        /** @var list<string> $codes */
        $codes = $this->confirm($user, $company, '123456')->json('data.recovery_codes');

        // Then exactly 10 distinct codes are returned, stored hashed (never plaintext)
        $this->assertRecoveryCodesHashed($user, $codes);
    }

    #[Test]
    public function when_enrollment_is_confirmed_then_the_recorded_event_carries_no_secret(): void
    {
        // Given (a Pro member completing enrollment)
        $company = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $user = $this->aMemberOf($company);
        $this->fakeTotpVerifier(verifies: true);
        $stagedSecret = (string) $this->enable($user, $company)->json('data.secret');

        // When they confirm
        $this->confirm($user, $company, '123456');

        // Then the recorded User2FAEnabled marker holds only non-secret metadata (Design-Y)
        $this->assertEnableMarkerCarriesNoSecret($stagedSecret);
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
        return $this->confirmWith($user, $company, ['code' => $code]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function confirmWith(User $user, Company $company, array $payload): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson(
            '/api/v1/auth/2fa/confirm',
            $payload,
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
        $reloaded = User::query()->whereKey($user->getKey())->firstOrFail();
        $this->assertNotNull($reloaded->two_factor_confirmed_at, '2FA must be marked enabled at confirm.');
        $this->assertNotEmpty($response->json('data.recovery_codes'), 'Recovery codes are shown once at confirm.');
        $this->assertSecretStoredEncrypted($user, $stagedSecret);
    }

    protected function assertSecretStoredEncrypted(User $user, string $stagedSecret): void
    {
        // Read the RAW column (bypassing the model's `encrypted` cast) so the test
        // would RED if the secret were ever stored in plaintext.
        $raw = (string) DB::table('users')->where('id', $user->getKey())->value('two_factor_secret');
        $this->assertNotSame($stagedSecret, $raw, 'The TOTP secret must be encrypted at rest, not plaintext.');
        $this->assertSame($stagedSecret, Crypt::decryptString($raw), 'The encrypted secret must decrypt to the staged value.');
    }

    /**
     * @param  list<string>  $returnedCodes
     */
    protected function assertRecoveryCodesHashed(User $user, array $returnedCodes): void
    {
        $stored = User::query()->whereKey($user->getKey())->firstOrFail()->two_factor_recovery_codes ?? [];
        $this->assertCount(10, $returnedCodes);
        $this->assertCount(10, array_unique($returnedCodes), 'Recovery codes must be distinct.');
        $this->assertSame([], array_intersect($returnedCodes, $stored), 'No plaintext code may appear in storage.');
        foreach ($returnedCodes as $index => $code) {
            $this->assertTrue(Hash::check($code, $stored[$index]), 'Every returned code must verify against its stored hash.');
        }
    }

    protected function assertEnableMarkerCarriesNoSecret(string $stagedSecret): void
    {
        $marker = LesilyStoredEvent::query()->where('event_class', User2FAEnabled::class)->sole();
        $properties = $marker->event_properties;
        $this->assertSame(['userId', 'confirmedAt'], array_keys($properties), 'The marker must carry only non-secret metadata.');
        $this->assertStringNotContainsString($stagedSecret, (string) json_encode($properties), 'The TOTP secret must never enter the event store.');
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
