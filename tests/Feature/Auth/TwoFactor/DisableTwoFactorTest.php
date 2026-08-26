<?php

namespace Tests\Feature\Auth\TwoFactor;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Auth\Events\User2FAEnabled;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Auth\Events\User2FADisabled;
use App\Foundation\Http\Responses\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
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
    public function when_the_password_is_missing_then_the_request_is_rejected(array $payload, string $expectedMessage): void
    {
        // Given an authenticated 2FA user
        Sanctum::actingAs($this->aTwoFactorUser());

        // When a disable is submitted without a valid password
        $response = $this->deleteJson('/api/v1/auth/2fa', $payload);

        // Then the request is rejected as a validation error naming the password
        $this->assertValidationRejected($response, 'password', $expectedMessage);
    }

    #[Test]
    public function when_two_factor_is_not_enabled_then_disabling_is_rejected(): void
    {
        // Given an authenticated user who has never enabled 2FA, signed in on a second device too
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user, 'web')->plainTextToken;
        $this->aSessionFor($user, 'mobile');

        // When they attempt to disable 2FA with their (correct) password
        $response = $this->withToken($current)->deleteJson('/api/v1/auth/2fa', ['password' => self::LOGIN_PASSWORD]);

        // Then it is refused — there is no 2FA to disable — and NO session is revoked (Minimal Guarantee)
        $this->assertRefusedWith($response, ApiErrorCode::TwoFactorNotEnabled);
        $response->assertStatus(422); // literal pins the 422 contract independent of the enum
        $response->assertJsonPath('message', 'Two-factor authentication is not enabled.'); // pins the bootstrap copy (Infection-blind)
        $this->assertSame(2, $user->tokens()->count(), 'A not-enabled refusal must revoke no sessions.');
    }

    #[Test]
    public function when_the_password_is_incorrect_then_two_factor_remains_enabled(): void
    {
        // Given an authenticated user with 2FA enabled, signed in on a second device too
        $user = $this->aTwoFactorUser();
        $current = $this->aSessionFor($user, 'web')->plainTextToken;
        $this->aSessionFor($user, 'mobile');

        // When they submit the wrong password to disable
        $response = $this->withToken($current)->deleteJson('/api/v1/auth/2fa', ['password' => 'not-my-password']);

        // Then it is refused, 2FA is left fully intact, and NO session is revoked (the Minimal Guarantee)
        $this->assertRefusedWith($response, ApiErrorCode::IncorrectPassword);
        $response->assertStatus(401); // literal pins the 401 contract independent of the enum
        $response->assertJsonPath('message', 'Incorrect password. Please try again.'); // pins the bootstrap copy (Infection-blind)
        $this->assertTwoFactorStillEnabled($user);
        $this->assertSame(2, $user->tokens()->count(), 'A refused disable must revoke no sessions.');
    }

    // Sad — disabling resets the enrollment flag, so a later re-enable is recorded rather
    // than swallowed by confirmTwoFactor's already-enabled guard (proves applyUser2FADisabled).
    #[Test]
    public function when_two_factor_is_re_enabled_after_being_disabled_then_a_new_enable_marker_is_recorded(): void
    {
        // Given a user who enabled then disabled 2FA
        $userId = (string) Str::uuid();
        UserAggregate::retrieve($userId)->confirmTwoFactor()->persist();
        UserAggregate::retrieve($userId)->disableTwoFactor()->persist();

        // When they enable 2FA again
        UserAggregate::retrieve($userId)->confirmTwoFactor()->persist();

        // Then a second enable marker is recorded — the disable reset the flag
        $this->assertSame(2, LesilyStoredEvent::query()->where('event_class', User2FAEnabled::class)->count());
    }

    #[Test]
    public function when_the_correct_password_is_submitted_then_two_factor_is_disabled(): void
    {
        // Given an authenticated user with 2FA enabled
        $user = $this->aTwoFactorUser();
        $token = $this->aSessionFor($user)->plainTextToken;

        // When they confirm the disable with their correct password
        $response = $this->withToken($token)->deleteJson('/api/v1/auth/2fa', ['password' => self::LOGIN_PASSWORD]);

        // Then 2FA is fully removed and a non-secret marker is recorded
        $this->assertTwoFactorDisabled($response, $user);
    }

    #[Test]
    public function when_two_factor_is_disabled_then_login_no_longer_requires_a_second_factor(): void
    {
        // Given a 2FA user who disables 2FA via the endpoint
        $user = $this->aTwoFactorUser();
        $token = $this->aSessionFor($user)->plainTextToken;
        $this->withToken($token)->deleteJson('/api/v1/auth/2fa', ['password' => self::LOGIN_PASSWORD])->assertNoContent();

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
    public function when_two_factor_is_disabled_then_the_users_other_sessions_are_revoked(): void
    {
        // Given a 2FA user signed in on two devices — the current one plus one other
        $user = $this->aTwoFactorUser();
        $current = $this->aSessionFor($user, 'web');
        $other = $this->aSessionFor($user, 'mobile');

        // When they disable 2FA with the correct password on the current device
        $response = $this->withToken($current->plainTextToken)->deleteJson('/api/v1/auth/2fa', ['password' => self::LOGIN_PASSWORD]);

        // Then the disable succeeds, the other device no longer authenticates, and the current session survives
        $this->assertOtherSessionsRevoked($response, $current->plainTextToken, $other->plainTextToken);
    }

    #[Test]
    public function when_disabling_past_the_rate_limit_then_it_is_throttled(): void
    {
        // Given an authenticated user hammering the disable endpoint (the password-guessing surface)
        $token = $this->aSessionFor($this->aTwoFactorUser())->plainTextToken;

        // When the shared per-IP auth-tier allowance is exhausted on wrong-password attempts
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withToken($token)->deleteJson('/api/v1/auth/2fa', ['password' => 'wrong-password']);
        }
        $response = $this->withToken($token)->deleteJson('/api/v1/auth/2fa', ['password' => 'wrong-password']);

        // Then the over-limit attempt is throttled — the brute-force surface is bounded
        $this->assertThrottled($response);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidDisablePayloads(): array
    {
        return [
            'missing password' => [[], 'The password field is required.'],
            'blank password' => [['password' => ''], 'The password field is required.'],
        ];
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

    /**
     * Disabling 2FA succeeded (204), revoked the user's OTHER sessions — the other device no
     * longer authenticates (forgetGuards forces a fresh DB re-check) — and kept the current
     * session alive. Goes RED if the revoke is removed (the other token would still authenticate).
     */
    protected function assertOtherSessionsRevoked(TestResponse $response, string $currentToken, string $otherToken): void
    {
        $response->assertNoContent();
        $this->assertUnauthenticated($this->probeWith($otherToken));
        $this->probeWith($currentToken)->assertOk();
    }
}
