<?php

namespace Tests\Feature\Auth\TwoFactor;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Auth\Events\User2FADisabled;
use App\Foundation\Http\Responses\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class DisableTwoFactorTest extends AuthTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_no_token_is_presented_then_disabling_is_rejected(): void
    {
        // Given no authenticated token

        // When a disable is attempted
        $response = $this->deleteJson('/api/v1/auth/2fa', ['password' => self::LOGIN_PASSWORD]);

        // Then the Sanctum guard rejects it before any work runs
        $this->assertUnauthenticated($response);
    }

    #[Test]
    #[DataProvider('provideInvalidDisablePayloads')]
    public function when_the_password_is_missing_then_the_request_is_rejected(array $payload): void
    {
        // Given an authenticated 2FA user
        Sanctum::actingAs($this->aTwoFactorUser());

        // When a disable is submitted without a valid password
        $response = $this->deleteJson('/api/v1/auth/2fa', $payload);

        // Then the request is rejected as a validation error naming the password
        $this->assertValidationRejected($response, 'password');
    }

    #[Test]
    public function when_two_factor_is_not_enabled_then_disabling_is_rejected(): void
    {
        // Given an authenticated user who has never enabled 2FA
        Sanctum::actingAs($this->aRegisteredUser());

        // When they attempt to disable 2FA with their (correct) password
        $response = $this->deleteJson('/api/v1/auth/2fa', ['password' => self::LOGIN_PASSWORD]);

        // Then it is refused — there is no 2FA to disable
        $this->assertRefusedWith($response, ApiErrorCode::TwoFactorNotEnabled);
        $response->assertStatus(422); // literal pins the 422 contract independent of the enum
        $response->assertJsonPath('message', 'Two-factor authentication is not enabled.'); // pins the bootstrap copy (Infection-blind)
    }

    #[Test]
    public function when_the_password_is_incorrect_then_two_factor_remains_enabled(): void
    {
        // Given an authenticated user with 2FA enabled
        $user = $this->aTwoFactorUser();
        Sanctum::actingAs($user);

        // When they submit the wrong password to disable
        $response = $this->deleteJson('/api/v1/auth/2fa', ['password' => 'not-my-password']);

        // Then it is refused and 2FA is left fully intact (the Minimal Guarantee)
        $this->assertRefusedWith($response, ApiErrorCode::IncorrectPassword);
        $response->assertStatus(401); // literal pins the 401 contract independent of the enum
        $response->assertJsonPath('message', 'Incorrect password. Please try again.'); // pins the bootstrap copy (Infection-blind)
        $this->assertTwoFactorStillEnabled($user);
    }

    #[Test]
    public function when_the_correct_password_is_submitted_then_two_factor_is_disabled(): void
    {
        // Given an authenticated user with 2FA enabled
        $user = $this->aTwoFactorUser();
        Sanctum::actingAs($user);

        // When they confirm the disable with their correct password
        $response = $this->deleteJson('/api/v1/auth/2fa', ['password' => self::LOGIN_PASSWORD]);

        // Then 2FA is fully removed and a non-secret marker is recorded
        $this->assertTwoFactorDisabled($response, $user);
    }

    #[Test]
    public function when_two_factor_is_disabled_then_login_no_longer_requires_a_second_factor(): void
    {
        // Given a 2FA user who disables 2FA via the endpoint
        $user = $this->aTwoFactorUser();
        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/auth/2fa', ['password' => self::LOGIN_PASSWORD])->assertNoContent();

        // When they log in again with email + password
        Auth::forgetGuards();
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);

        // Then no second factor is required — a session token is issued (200, not a 202 challenge)
        $response->assertOk();
        $this->assertNotEmpty($response->json('data.token'), 'Disabling 2FA must let the user log in without a 2FA challenge.');
    }

    #[Test]
    public function when_disabling_past_the_rate_limit_then_it_is_throttled(): void
    {
        // Given an authenticated user hammering the disable endpoint (the password-guessing surface)
        Sanctum::actingAs($this->aTwoFactorUser());

        // When the shared per-IP auth-tier allowance is exhausted on wrong-password attempts
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->deleteJson('/api/v1/auth/2fa', ['password' => 'wrong-password']);
        }
        $response = $this->deleteJson('/api/v1/auth/2fa', ['password' => 'wrong-password']);

        // Then the over-limit attempt is throttled — the brute-force surface is bounded
        $this->assertThrottled($response);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function provideInvalidDisablePayloads(): array
    {
        return [
            'missing password' => [[]],
            'blank password' => [['password' => '']],
        ];
    }

    protected function assertRefusedWith(TestResponse $response, ApiErrorCode $code): void
    {
        $response->assertStatus($code->status());
        $response->assertJsonPath('error_code', $code->value);
    }

    protected function assertTwoFactorStillEnabled(User $user): void
    {
        $stored = User::query()->whereKey($user->getKey())->firstOrFail();
        $this->assertNotNull($stored->two_factor_secret, 'The TOTP secret must survive a refused disable.');
        $this->assertNotNull($stored->two_factor_recovery_codes, 'Recovery codes must survive a refused disable.');
        $this->assertNotNull($stored->two_factor_confirmed_at, '2FA must remain enabled after a refused disable.');
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', User2FADisabled::class)->count(), 'A refused disable records no marker.');
    }

    protected function assertTwoFactorDisabled(TestResponse $response, User $user): void
    {
        $response->assertNoContent();
        $stored = User::query()->whereKey($user->getKey())->firstOrFail();
        $this->assertNull($stored->two_factor_secret, 'The TOTP secret must be wiped.');
        $this->assertNull($stored->two_factor_recovery_codes, 'Recovery codes must be wiped.');
        $this->assertNull($stored->two_factor_confirmed_at, '2FA must be marked disabled.');
        $this->assertDisableMarkerRecorded($user);
    }

    protected function assertDisableMarkerRecorded(User $user): void
    {
        // sole() enforces exactly-one marker (throws otherwise) and is non-null, so the
        // property reads below need no nullsafe (mirrors EnableTwoFactorTest).
        $properties = LesilyStoredEvent::query()
            ->where('event_class', User2FADisabled::class)
            ->sole()
            ->event_properties;
        $this->assertSame(['userId', 'disabledAt'], array_keys($properties), 'The marker must carry only non-secret metadata (Design-Y).');
        $this->assertSame($user->id, $properties['userId'] ?? null, 'The marker must be scoped to the disabling user.');
    }
}
