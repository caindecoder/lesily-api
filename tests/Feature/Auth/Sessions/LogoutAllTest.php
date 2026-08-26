<?php

namespace Tests\Feature\Auth\Sessions;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LogoutAllTest extends AuthTestCase
{
    use RefreshDatabase;

    // Sad — the logout-all endpoint is guarded: a request with no token is rejected by the
    // Sanctum guard before any password check or revocation runs (proves auth:sanctum is on).
    #[Test]
    public function when_no_token_is_presented_then_logging_out_all_is_rejected(): void
    {
        // Given no authenticated token

        // When logging out all devices is attempted
        $response = $this->postJson('/api/v1/auth/logout-all', ['password' => self::LOGIN_PASSWORD]);

        // Then the Sanctum guard rejects it
        $this->assertUnauthenticated($response);
    }

    // Sad — the password is the re-auth gate: an authenticated request that omits it (or sends
    // a blank one) is rejected as a validation error before any revocation runs.
    #[Test]
    #[DataProvider('provideInvalidLogoutAllPayloads')]
    public function when_the_password_is_missing_then_the_request_is_rejected(array $payload, string $expectedMessage): void
    {
        // Given an authenticated user holding a live session
        $token = $this->aSessionFor($this->aRegisteredUser())->plainTextToken;

        // When logout-all is submitted without a valid password
        $response = $this->withToken($token)->postJson('/api/v1/auth/logout-all', $payload);

        // Then the request is rejected as a validation error naming the password
        $this->assertValidationRejected($response, 'password', $expectedMessage);
    }

    // Sad — wrong password is the re-auth gate: it is refused and NONE of the user's sessions
    // are revoked (the Minimal Guarantee — a failed confirm must leave every device logged in).
    #[Test]
    public function when_the_password_is_incorrect_then_all_sessions_are_preserved(): void
    {
        // Given a user with three active sessions (the current one plus two others)
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');
        $this->aSessionFor($user, 'tablet');

        // When they confirm logout-all with the wrong password
        $response = $this->logoutAll($current->plainTextToken, 'not-my-password');

        // Then it is refused and all three sessions remain intact
        $this->assertRefusedWith($response, ApiErrorCode::IncorrectPassword);
        $response->assertStatus(401); // literal pins the 401 contract independent of the enum
        $response->assertJsonPath('message', 'Incorrect password. Please try again.'); // pins the bootstrap copy (Infection-blind)
        $this->assertSessionsPreserved($user, 3);
    }

    // Sad — logout-all runs Hash::check, so it is a password-guessing surface: once the shared
    // per-IP auth-tier allowance is exhausted, further attempts are throttled (the brute-force
    // surface is bounded, exactly like /2fa disable).
    #[Test]
    public function when_logging_out_all_past_the_rate_limit_then_it_is_throttled(): void
    {
        // Given an authenticated user hammering logout-all with wrong-password attempts
        $token = $this->aSessionFor($this->aRegisteredUser())->plainTextToken;

        // When the shared per-IP auth-tier allowance is exhausted
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->logoutAll($token, 'wrong-password');
        }
        $response = $this->logoutAll($token, 'wrong-password');

        // Then the over-limit attempt is throttled — the brute-force surface is bounded
        $this->assertThrottled($response);
    }

    // Edge — logout-all is account-scoped: when Alice logs out all her devices, another user's
    // sessions are never touched. Account-level tokens carry no company scope, so "own only" IS
    // the tenant-isolation guarantee.
    #[Test]
    public function when_a_user_logs_out_all_then_other_users_sessions_remain_active(): void
    {
        // Given Alice (with a second device) and Bob, each holding active sessions
        $alice = $this->aRegisteredUser();
        $aliceCurrent = $this->aSessionFor($alice, 'web');
        $this->aSessionFor($alice, 'mobile');

        $bob = $this->aRegisteredUser('bob@contoso.lk');
        $bobToken = $this->aSessionFor($bob, 'web');
        $this->aSessionFor($bob, 'mobile');

        // When Alice logs out all her other devices with her correct password
        $this->logoutAll($aliceCurrent->plainTextToken, self::LOGIN_PASSWORD)->assertOk();

        // Then Bob's sessions are wholly untouched
        $this->assertSessionsIntact($bob, $bobToken->plainTextToken, 2);
    }

    // Edge — a user whose only session is the current one has nothing else to revoke: logout-all
    // succeeds, reports zero revoked, and leaves them logged in.
    #[Test]
    public function when_only_the_current_session_exists_then_logging_out_all_revokes_none(): void
    {
        // Given a user holding exactly one (current) session
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user);

        // When they log out all other devices with the correct password
        $response = $this->logoutAll($current->plainTextToken, self::LOGIN_PASSWORD);

        // Then nothing is revoked and their current session still authenticates
        $this->assertOnlyCurrentSurvives($response, $current->plainTextToken, 0);
    }

    // Edge — revoke is expiry-agnostic: logout-all deletes EVERY other session row the user owns,
    // including an expired-but-unpruned one, and counts it — deliberately distinct from the active
    // sessions LIST (UC-1-010), which hides expired rows (consistent with the Task-3 single-revoke
    // semantic: revoke = "delete my rows", not "delete my active rows").
    #[Test]
    public function when_other_sessions_include_an_expired_one_then_logging_out_all_revokes_them_all(): void
    {
        // Given a user with the current session, one live other device, and one expired (unpruned) row
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user, 'web');
        $liveOther = $this->aSessionFor($user, 'mobile');
        $this->anExpiredSessionFor($user, 'old-laptop');

        // When they log out all other devices with the correct password
        $response = $this->logoutAll($current->plainTextToken, self::LOGIN_PASSWORD);

        // Then both other rows (live + expired) are revoked and counted, only the current remains,
        // and the live other device no longer authenticates
        $this->assertOnlyCurrentSurvives($response, $current->plainTextToken, 2);
        $this->assertSessionsPreserved($user, 1);
        $this->assertUnauthenticated($this->probeWith($liveOther->plainTextToken));
    }

    // Happy — the core flow: a user with several devices confirms with the correct password and
    // every OTHER session is revoked, while the device they are using stays logged in.
    #[Test]
    public function when_the_correct_password_is_submitted_then_the_other_sessions_are_revoked(): void
    {
        // Given a user with four active sessions: the current one plus three others
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');
        $this->aSessionFor($user, 'tablet');
        $this->aSessionFor($user, 'laptop');

        // When they log out all other devices with the correct password
        $response = $this->logoutAll($current->plainTextToken, self::LOGIN_PASSWORD);

        // Then the three other sessions are revoked and only the current one remains
        $this->assertOnlyCurrentSurvives($response, $current->plainTextToken, 3);
        $this->assertSessionsPreserved($user, 1);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidLogoutAllPayloads(): array
    {
        return [
            'missing password' => [[], 'The password field is required.'],
            'blank password' => [['password' => ''], 'The password field is required.'],
        ];
    }

    private function logoutAll(string $token, string $password): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/auth/logout-all', ['password' => $password]);
    }

    /**
     * No session was revoked: the user still holds exactly $expected tokens. The "nothing
     * revoked" oracle for the refused / no-op paths — goes RED if a revoke ever runs before
     * (or instead of) the password gate (the Minimal Guarantee).
     */
    protected function assertSessionsPreserved(User $user, int $expected): void
    {
        $this->assertSame($expected, $user->tokens()->count());
    }

    /**
     * Another user's sessions are wholly unaffected by the actor's logout-all: their token
     * count is unchanged AND one of their tokens still authenticates (the cross-user isolation
     * oracle — count alone would miss a swapped row).
     */
    protected function assertSessionsIntact(User $user, string $survivingToken, int $expected): void
    {
        $this->assertSame($expected, $user->tokens()->count());
        $this->probeWith($survivingToken)->assertOk();
    }

    /**
     * Logout-all succeeded, reported exactly $expectedRevoked tokens revoked, and the current
     * session still authenticates (forgetGuards forces a fresh DB re-check) — the user is never
     * accidentally logged out of the device they are using.
     */
    protected function assertOnlyCurrentSurvives(TestResponse $response, string $currentToken, int $expectedRevoked): void
    {
        $response->assertOk()->assertJsonPath('data.tokens_revoked', $expectedRevoked);
        $this->probeWith($currentToken)->assertOk();
    }
}
