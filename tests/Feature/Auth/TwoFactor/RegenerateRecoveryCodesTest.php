<?php

namespace Tests\Feature\Auth\TwoFactor;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\Auth\Events\User2FARecoveryCodesRegenerated;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class RegenerateRecoveryCodesTest extends AuthTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_no_token_is_presented_then_regeneration_is_rejected(): void
    {
        // Given no authenticated token

        // When a regeneration is attempted
        $response = $this->postJson('/api/v1/auth/2fa/recovery-codes', ['password' => self::LOGIN_PASSWORD]);

        // Then the Sanctum guard rejects it before any work runs
        $this->assertUnauthenticated($response);
    }

    #[Test]
    #[DataProvider('provideInvalidRegeneratePayloads')]
    public function when_the_password_is_missing_then_the_request_is_rejected(array $payload): void
    {
        // Given an authenticated 2FA user
        Sanctum::actingAs($this->aTwoFactorUser());

        // When a regeneration is submitted without a valid password
        $response = $this->postJson('/api/v1/auth/2fa/recovery-codes', $payload);

        // Then the request is rejected as a validation error naming the password
        $this->assertValidationRejected($response, 'password');
    }

    #[Test]
    public function when_two_factor_is_not_enabled_then_regeneration_is_rejected(): void
    {
        // Given an authenticated user who has never enabled 2FA
        Sanctum::actingAs($this->aRegisteredUser());

        // When they attempt to regenerate recovery codes with their (correct) password
        $response = $this->postJson('/api/v1/auth/2fa/recovery-codes', ['password' => self::LOGIN_PASSWORD]);

        // Then it is refused — there is no 2FA to manage
        $this->assertRefusedWith($response, ApiErrorCode::TwoFactorNotEnabled);
        $response->assertStatus(422); // literal pins the 422 contract independent of the enum
        $response->assertJsonPath('message', 'Two-factor authentication is not enabled.');
    }

    #[Test]
    public function when_the_password_is_incorrect_then_the_existing_codes_are_unchanged(): void
    {
        // Given an authenticated 2FA user holding known recovery codes
        $user = $this->aTwoFactorUser(['KEEP-CODE', 'ALSO-KEEP']);
        Sanctum::actingAs($user);

        // When they submit the wrong password to regenerate
        $response = $this->postJson('/api/v1/auth/2fa/recovery-codes', ['password' => 'not-my-password']);

        // Then it is refused and the existing codes are left intact (the Minimal Guarantee)
        $this->assertRefusedWith($response, ApiErrorCode::IncorrectPassword);
        $response->assertStatus(401); // literal pins the 401 contract independent of the enum
        $response->assertJsonPath('message', 'Incorrect password. Please try again.');
        $this->assertRecoveryCodesUnchanged($user, 'KEEP-CODE');
    }

    #[Test]
    public function when_regeneration_is_attempted_past_the_rate_limit_then_it_is_throttled(): void
    {
        // Given an authenticated user hammering the regenerate endpoint (the password-guessing surface)
        Sanctum::actingAs($this->aTwoFactorUser());

        // When the shared per-IP auth-tier allowance is exhausted on wrong-password attempts
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/auth/2fa/recovery-codes', ['password' => 'wrong-password']);
        }
        $response = $this->postJson('/api/v1/auth/2fa/recovery-codes', ['password' => 'wrong-password']);

        // Then the over-limit attempt is throttled — the brute-force surface is bounded
        $this->assertThrottled($response);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function provideInvalidRegeneratePayloads(): array
    {
        return [
            'missing password' => [[]],
            'blank password' => [['password' => '']],
        ];
    }

    protected function assertRecoveryCodesUnchanged(User $user, string $survivingCode): void
    {
        $stored = User::query()->whereKey($user->getKey())->firstOrFail()->two_factor_recovery_codes ?? [];
        $matches = array_filter($stored, static fn (string $hash): bool => Hash::check($survivingCode, $hash));
        $this->assertNotEmpty($matches, 'A refused regenerate must leave the existing recovery codes valid.');
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', User2FARecoveryCodesRegenerated::class)->count(), 'A refused regenerate records no marker.');
    }
}
