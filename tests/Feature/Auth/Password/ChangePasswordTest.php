<?php

namespace Tests\Feature\Auth\Password;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ChangePasswordTest extends AuthTestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'New-Str0ng-Pass!';

    protected function setUp(): void
    {
        parent::setUp();

        // Error-path render parity (amendment M): APP_DEBUG=true (the local default)
        // masks the prod render path. The 401/422/429 rejections below must render their
        // mapped envelopes the way CI/prod do.
        config(['app.debug' => false]);
    }

    #[Test]
    public function when_no_session_is_provided_then_the_request_is_unauthenticated(): void
    {
        // Given no authenticated token

        // When a password change is attempted
        $response = $this->putJson('/api/v1/me/password', [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the Sanctum guard rejects it before any work runs
        $this->assertUnauthenticated($response);
    }

    #[Test]
    public function when_the_current_password_is_incorrect_then_the_password_is_unchanged(): void
    {
        // Given an authenticated user signed in on two devices — the current one plus one other
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');

        // When they submit the wrong current password
        $response = $this->changePassword($current->plainTextToken, [
            'currentPassword' => 'not-my-password',
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then it is refused, the password is left intact, and NO session is revoked (Minimal Guarantee)
        $this->assertRefusedWith($response, ApiErrorCode::IncorrectPassword);
        $response->assertStatus(401); // literal pins the 401 contract independent of the enum
        $response->assertJsonPath('message', 'Incorrect password. Please try again.'); // pins the bootstrap copy (Infection-blind)
        $this->assertPasswordUnchanged($user, 2);
    }

    #[Test]
    public function when_the_new_password_matches_the_current_password_then_it_is_rejected(): void
    {
        // Given an authenticated user
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user);

        // When they submit a new password identical to their current one
        $response = $this->changePassword($current->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::LOGIN_PASSWORD,
        ]);

        // Then it is rejected as a validation error naming the new password, and nothing changes
        $this->assertValidationRejected($response, 'newPassword');
        $this->assertPasswordUnchanged($user, 1);
    }

    #[Test]
    #[DataProvider('provideInvalidChangePasswordPayloads')]
    public function when_the_new_password_is_invalid_then_it_is_rejected(array $payload, string $invalidField): void
    {
        // Given an authenticated user
        $current = $this->aSessionFor($this->aRegisteredUser());

        // When they submit a change payload that breaks a field rule
        $response = $this->changePassword($current->plainTextToken, $payload);

        // Then the request is rejected as a validation error naming the offending field
        $this->assertValidationRejected($response, $invalidField);
    }

    #[Test]
    public function when_the_password_is_changed_then_the_other_sessions_are_revoked(): void
    {
        // Given an authenticated user signed in on two devices — the current one plus one other
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user, 'web');
        $other = $this->aSessionFor($user, 'mobile');

        // When they change their password from the current device with the correct current password
        $response = $this->changePassword($current->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the change succeeds, the other device no longer authenticates, and the current session survives
        $this->assertOtherSessionsRevoked($response, $current->plainTextToken, $other->plainTextToken);
        $this->assertSame(1, $user->tokens()->count(), 'Only the current session must remain after a change.');
    }

    #[Test]
    public function when_the_current_password_is_correct_then_the_password_is_replaced(): void
    {
        // Given an authenticated user
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user);

        // When they change their password with the correct current password
        $response = $this->changePassword($current->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the change succeeds and the new password fully replaces the old one
        $response->assertNoContent();
        $this->assertPasswordReplaced($user, self::LOGIN_PASSWORD, self::NEW_PASSWORD);
    }

    #[Test]
    public function when_too_many_attempts_are_made_then_the_request_is_throttled(): void
    {
        // Given an authenticated user hammering the change endpoint (the password-guessing surface)
        $token = $this->aSessionFor($this->aRegisteredUser())->plainTextToken;

        // When the shared per-IP auth-tier allowance is exhausted on wrong-password attempts
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->changePassword($token, ['currentPassword' => 'wrong-password', 'newPassword' => self::NEW_PASSWORD]);
        }
        $response = $this->changePassword($token, ['currentPassword' => 'wrong-password', 'newPassword' => self::NEW_PASSWORD]);

        // Then the over-limit attempt is throttled — the brute-force surface is bounded
        $this->assertThrottled($response);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function provideInvalidChangePasswordPayloads(): array
    {
        return [
            'missing new password' => [['currentPassword' => self::LOGIN_PASSWORD], 'newPassword'],
            'new password under eight characters' => [['currentPassword' => self::LOGIN_PASSWORD, 'newPassword' => 'Sh0rt!'], 'newPassword'],
            'missing current password' => [['newPassword' => self::NEW_PASSWORD], 'currentPassword'],
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function changePassword(string $token, array $payload, ?string $idempotencyKey = null): TestResponse
    {
        return $this->withToken($token)->putJson('/api/v1/me/password', $payload, [
            'Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid(),
        ]);
    }

    /**
     * The change was refused: the original password still authenticates, the attempted new
     * password was NOT stored, and not a single session was revoked (the Minimal Guarantee —
     * goes RED if any write or revoke leaks past a refused change).
     */
    protected function assertPasswordUnchanged(User $user, int $expectedSessions): void
    {
        $stored = User::query()->whereKey($user->getKey())->firstOrFail();
        $this->assertTrue(Hash::check(self::LOGIN_PASSWORD, $stored->password), 'The original password must still authenticate.');
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $stored->password), 'A rejected new password must not be stored.');
        $this->assertSame($expectedSessions, $user->tokens()->count(), 'A rejected change must revoke no sessions.');
    }

    /**
     * The change succeeded (204), revoked the user's OTHER sessions — the other device no longer
     * authenticates (forgetGuards forces a fresh DB re-check) — and kept the current session alive.
     * Goes RED if the revoke is removed (the other token would still authenticate).
     */
    protected function assertOtherSessionsRevoked(TestResponse $response, string $currentToken, string $otherToken): void
    {
        $response->assertNoContent();
        $this->assertUnauthenticated($this->probeWith($otherToken));
        $this->probeWith($currentToken)->assertOk();
    }

    /**
     * The new password fully replaced the old one end-to-end: a fresh login with the OLD password
     * is rejected (generic credentials error) and a fresh login with the NEW one succeeds with a
     * session token. Proves the projector wrote the new hash to the read model (the password column
     * is projected). forgetGuards between attempts forces fresh credential resolution, not a cached guard.
     */
    protected function assertPasswordReplaced(User $user, string $oldPassword, string $newPassword): void
    {
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $oldPassword])
            ->assertStatus(401);

        Auth::forgetGuards();
        $newLogin = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $newPassword]);
        $newLogin->assertOk();
        $this->assertNotEmpty($newLogin->json('data.token'), 'The new password must authenticate and issue a session.');
    }
}
