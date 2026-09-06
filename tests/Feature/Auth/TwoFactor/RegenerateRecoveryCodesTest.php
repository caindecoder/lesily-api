<?php

namespace Tests\Feature\Auth\TwoFactor;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\Auth\Events\User2FARecoveryCodesRegenerated;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class RegenerateRecoveryCodesTest extends AuthTestCase
{
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
    public function when_the_password_is_missing_then_the_request_is_rejected(array $payload, string $expectedMessage): void
    {
        // Given an authenticated 2FA user
        Sanctum::actingAs($this->aTwoFactorUser());

        // When a regeneration is submitted without a valid password
        $response = $this->postJson('/api/v1/auth/2fa/recovery-codes', $payload);

        // Then the request is rejected as a validation error naming the password
        $this->assertValidationRejected($response, 'password', $expectedMessage);
    }

    #[Test]
    public function when_two_factor_is_not_enabled_then_regeneration_is_rejected(): void
    {
        // Given an authenticated user who has never enabled 2FA
        Sanctum::actingAs($this->aRegisteredUser());

        // When they attempt to regenerate recovery codes with their (correct) password
        $response = $this->postJson('/api/v1/auth/2fa/recovery-codes', ['password' => self::LOGIN_PASSWORD]);

        // Then it is refused — there is no 2FA to manage
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::TwoFactorNotEnabled);
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
        $this->assertRefusedWith($response, Response::HTTP_UNAUTHORIZED, ApiErrorCode::IncorrectPassword);
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

    #[Test]
    public function when_the_correct_password_is_submitted_then_the_recovery_codes_are_rotated(): void
    {
        // Given an authenticated 2FA user
        $user = $this->aTwoFactorUser();
        Sanctum::actingAs($user);
        $secretBefore = (string) User::query()->whereKey($user->getKey())->firstOrFail()->two_factor_secret;

        // When they regenerate with the correct password
        $response = $this->postJson('/api/v1/auth/2fa/recovery-codes', ['password' => self::LOGIN_PASSWORD]);

        // Then 10 fresh codes are returned once, stored hashed, the TOTP secret is kept, and a marker is recorded
        $this->assertCodesRotated($response, $user, $secretBefore);
    }

    #[Test]
    public function when_codes_are_regenerated_then_old_codes_stop_working_and_new_codes_authenticate(): void
    {
        // Given a 2FA user holding a known recovery code, whose TOTP will not match (forcing the recovery path)
        $user = $this->aTwoFactorUser(['OLD-CODE', 'KEEP-CODE']);
        $this->fakeTotpVerifier(verifies: false);
        Sanctum::actingAs($user);

        // When they regenerate their recovery codes
        /** @var list<string> $newCodes */
        $newCodes = $this->postJson('/api/v1/auth/2fa/recovery-codes', ['password' => self::LOGIN_PASSWORD])
            ->assertOk()
            ->json('data.recovery_codes');

        // Then the old code no longer authenticates at verify, but a new one does (rotation end-to-end)
        $this->assertOldCodeRejectedAndNewAccepted('OLD-CODE', $newCodes[0], $user);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidRegeneratePayloads(): array
    {
        return [
            'missing password' => [[], 'The password field is required.'],
            'blank password' => [['password' => ''], 'The password field is required.'],
        ];
    }

    protected function assertRecoveryCodesUnchanged(User $user, string $survivingCode): void
    {
        $stored = User::query()->whereKey($user->getKey())->firstOrFail()->two_factor_recovery_codes ?? [];
        $matches = array_filter($stored, static fn (string $hash): bool => Hash::check($survivingCode, $hash));
        $this->assertNotEmpty($matches, 'A refused regenerate must leave the existing recovery codes valid.');
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', User2FARecoveryCodesRegenerated::class)->count(), 'A refused regenerate records no marker.');
    }

    protected function assertCodesRotated(TestResponse $response, User $user, string $secretBefore): void
    {
        $response->assertOk();
        /** @var list<string> $returned */
        $returned = $response->json('data.recovery_codes');
        $reloaded = User::query()->whereKey($user->getKey())->firstOrFail();
        $stored = $reloaded->two_factor_recovery_codes ?? [];

        $this->assertCount(10, $returned);
        $this->assertCount(10, array_unique($returned), 'Regenerated codes must be distinct.');
        $this->assertSame([], array_intersect($returned, $stored), 'No plaintext code may appear in storage.');
        foreach ($returned as $index => $code) {
            $this->assertTrue(Hash::check($code, $stored[$index]), 'Every returned code must verify against its stored hash.');
        }
        $this->assertSame($secretBefore, (string) $reloaded->two_factor_secret, 'The TOTP secret must be unchanged (no QR re-scan).');
        $this->assertNotNull($reloaded->two_factor_confirmed_at, '2FA must remain enabled.');
        $this->assertRegenerateMarkerRecorded($user);
    }

    /**
     * One rotation invariant, two inseparable facets: the pre-rotation code is dead at
     * verify while a freshly issued code authenticates. Each verify consumes its own
     * challenge (single-use).
     */
    protected function assertOldCodeRejectedAndNewAccepted(string $oldCode, string $newCode, User $user): void
    {
        $this->verify($this->openChallenge($user), $oldCode)->assertUnauthorized();
        $this->verify($this->openChallenge($user), $newCode)->assertOk();
    }

    protected function assertRegenerateMarkerRecorded(User $user): void
    {
        // sole() enforces exactly-one marker (throws otherwise) and is non-null, so the
        // property reads below need no nullsafe (mirrors EnableTwoFactorTest).
        $properties = LesilyStoredEvent::query()
            ->where('event_class', User2FARecoveryCodesRegenerated::class)
            ->sole()
            ->event_properties;
        $this->assertSame(['userId', 'regeneratedAt'], array_keys($properties), 'The marker must carry only non-secret metadata (Design-Y).');
        $this->assertSame($user->id, $properties['userId'] ?? null, 'The marker must be scoped to the regenerating user.');
    }
}
