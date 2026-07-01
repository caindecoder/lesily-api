<?php

namespace Tests\Feature\Auth\Password;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Resetting a forgotten password via the emailed link (UC-1-008, reset portion). A guest submits a
 * single-use token + their email + a new password; a valid token replaces the password (event-sourced),
 * invalidates ALL of the user's sessions and confirms by email, while an invalid / expired / used token
 * is collapsed to a single 422 RESET_LINK_INVALID (the broker deletes a consumed token, so expired vs
 * used are indistinguishable — collapsing also avoids leaking token state).
 */
class ResetPasswordTest extends AuthTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Error-path render parity (amendment M): APP_DEBUG=true (the local default) masks the prod
        // render path, so the 422/429 rejections below must render their mapped envelopes as CI/prod do.
        config(['app.debug' => false]);
    }

    #[Test]
    #[DataProvider('provideInvalidResetPayloads')]
    public function when_the_submission_is_invalid_then_it_is_rejected(array $payload, string $invalidField): void
    {
        // Given an invalid reset-password submission
        // When it is submitted
        $response = $this->resetPassword($payload);

        // Then it is rejected as a validation error naming the offending field
        $this->assertValidationRejected($response, $invalidField);
    }

    #[Test]
    public function when_the_reset_token_is_malformed_then_the_reset_is_refused(): void
    {
        // Given a registered user with an active session, and a token that was never issued
        $user = $this->aRegisteredUser();
        $this->aSessionFor($user);

        // When a reset is submitted with that garbage token
        $response = $this->resetPassword([
            'token' => 'never-issued-token',
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ]);

        // Then it is refused, the password is untouched, and the session survives (Minimal Guarantee)
        $this->assertRefusedWith($response, ApiErrorCode::ResetLinkInvalid);
        $response->assertStatus(422); // literal pins the 422 contract independent of the enum
        $this->assertResetRefused($user, self::LOGIN_PASSWORD, 1);
    }

    #[Test]
    public function when_the_reset_token_has_expired_then_the_reset_is_refused(): void
    {
        // Given a reset token that was issued more than 60 minutes ago
        $user = $this->aRegisteredUser();
        $token = $this->aResetTokenFor($user);
        $this->travel(61)->minutes();

        // When it is used to reset the password
        $response = $this->resetPassword([
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ]);

        // Then it is refused with the same collapsed error and nothing changes
        $this->assertRefusedWith($response, ApiErrorCode::ResetLinkInvalid);
        $response->assertStatus(422);
        $this->assertResetRefused($user, self::LOGIN_PASSWORD, 0);
    }

    #[Test]
    public function when_the_reset_token_has_already_been_used_then_the_reset_is_refused(): void
    {
        // Given a token already consumed by a successful reset
        $user = $this->aRegisteredUser();
        $token = $this->aResetTokenFor($user);
        $this->resetPassword([
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ])->assertNoContent();

        // When the same token is submitted again
        $response = $this->resetPassword([
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ]);

        // Then the reuse is refused with the collapsed error — the token is single-use
        $this->assertRefusedWith($response, ApiErrorCode::ResetLinkInvalid);
        $response->assertStatus(422);
    }

    #[Test]
    public function when_the_email_does_not_match_the_token_then_the_reset_is_refused(): void
    {
        // Given a token issued for Alice and a second account, Bob
        $alice = $this->aRegisteredUser();
        $bob = $this->aRegisteredUser('bob@contoso.lk');
        $aliceToken = $this->aResetTokenFor($alice);

        // When Alice's token is submitted with Bob's email
        $response = $this->resetPassword([
            'token' => $aliceToken,
            'email' => $bob->email,
            'password' => self::NEW_PASSWORD,
        ]);

        // Then the reset is refused and neither account's password changes (a token is bound to its email)
        $this->assertRefusedWith($response, ApiErrorCode::ResetLinkInvalid);
        $response->assertStatus(422);
        $this->assertResetRefused($alice, self::LOGIN_PASSWORD, 0);
        $this->assertResetRefused($bob, self::LOGIN_PASSWORD, 0);
    }

    #[Test]
    public function when_too_many_resets_are_attempted_then_they_are_throttled(): void
    {
        // Given the shared per-IP auth-tier allowance is exhausted against the reset endpoint
        $this->exhaustAuthLimit(10, '/api/v1/auth/reset-password');

        // When one more reset is attempted
        $response = $this->resetPassword([]);

        // Then the over-limit attempt is throttled — the reset endpoint is a brute-force surface
        $this->assertThrottled($response);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function provideInvalidResetPayloads(): array
    {
        return [
            'missing token' => [['email' => 'alice@contoso.lk', 'password' => self::NEW_PASSWORD], 'token'],
            'missing email' => [['token' => 'a-token', 'password' => self::NEW_PASSWORD], 'email'],
            'malformed email' => [['token' => 'a-token', 'email' => 'not-an-email', 'password' => self::NEW_PASSWORD], 'email'],
            'missing password' => [['token' => 'a-token', 'email' => 'alice@contoso.lk'], 'password'],
            'password under eight characters' => [['token' => 'a-token', 'email' => 'alice@contoso.lk', 'password' => 'Sh0rt!'], 'password'],
            'password over the length cap' => [['token' => 'a-token', 'email' => 'alice@contoso.lk', 'password' => str_repeat('a', 256)], 'password'],
        ];
    }

    /**
     * The reset was refused: the original password still authenticates, the attempted new password
     * (self::NEW_PASSWORD) was NOT stored, and not a single session was revoked (the Minimal Guarantee —
     * goes RED if any write or revoke leaks past a refused reset).
     */
    protected function assertResetRefused(User $user, string $originalPassword, int $expectedSessions): void
    {
        $stored = User::query()->whereKey($user->getKey())->firstOrFail();
        $this->assertTrue(Hash::check($originalPassword, $stored->password), 'The original password must still authenticate.');
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $stored->password), 'A rejected new password must not be stored.');
        $this->assertSame($expectedSessions, $user->tokens()->count(), 'A rejected reset must revoke no sessions.');
    }
}
