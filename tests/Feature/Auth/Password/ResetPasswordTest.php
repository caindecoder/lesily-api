<?php

namespace Tests\Feature\Auth\Password;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\Auth\Events\UserPasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

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

    #[Test]
    public function when_a_valid_reset_is_submitted_then_the_password_is_replaced(): void
    {
        // Given a registered user and a valid reset token issued for them
        $user = $this->aRegisteredUser();
        $token = $this->aResetTokenFor($user);

        // When they reset their password with a valid new one
        $response = $this->resetPassword([
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ]);

        // Then the reset succeeds, the new password fully replaces the old one, and the write was event-sourced
        $response->assertNoContent();
        $this->assertPasswordReplaced($user, self::LOGIN_PASSWORD, self::NEW_PASSWORD);
        $this->assertResetWasEventSourced(self::NEW_PASSWORD);
    }

    #[Test]
    public function when_the_password_is_reset_then_all_sessions_are_revoked(): void
    {
        // Given a registered user signed in on two devices, with a valid reset token
        $user = $this->aRegisteredUser();
        $web = $this->aSessionFor($user, 'web');
        $mobile = $this->aSessionFor($user, 'mobile');
        $token = $this->aResetTokenFor($user);

        // When they reset their password
        $response = $this->resetPassword([
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ]);

        // Then the reset succeeds and every one of their sessions is revoked (they re-log in with the new pw)
        $this->assertAllSessionsRevoked($response, $user, [$web->plainTextToken, $mobile->plainTextToken]);
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

    /**
     * The reset write was event-sourced, not a direct read-model save: exactly one UserPasswordReset was
     * stored and it carries the NEW password's hash. Goes RED if the interactor writes users.password
     * directly — the behavioural tests cannot catch that (a direct write yields identical read-model state),
     * yet it would be silently reverted on a projection replay (amendment R).
     */
    protected function assertResetWasEventSourced(string $newPassword): void
    {
        $stored = LesilyStoredEvent::query()->where('event_class', UserPasswordReset::class)->sole();
        $this->assertTrue(
            Hash::check($newPassword, $stored->event_properties['passwordHash']),
            'The stored UserPasswordReset must carry the new password hash (event-sourced write, not a direct save).',
        );
    }

    /**
     * The reset succeeded (204) and revoked ALL of the user's sessions — every prior token no longer
     * authenticates (forgetGuards forces a fresh DB re-check) and not one token row remains. Goes RED if
     * the revoke-all is a no-op — the TokenRevoker null (revoke-all) branch.
     *
     * @param  list<string>  $priorTokens
     */
    protected function assertAllSessionsRevoked(TestResponse $response, User $user, array $priorTokens): void
    {
        $response->assertNoContent();
        foreach ($priorTokens as $token) {
            $this->assertUnauthenticated($this->probeWith($token));
        }
        $this->assertSame(0, $user->tokens()->count(), 'A reset must revoke every one of the user\'s sessions.');
    }
}
