<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LogoutTest extends AuthTestCase
{
    use RefreshDatabase;

    // Sad — the logout endpoint is guarded: a request with no token is rejected
    // by the Sanctum guard before any revocation runs (proves auth:sanctum is on).
    #[Test]
    public function when_no_token_is_presented_then_logout_is_rejected(): void
    {
        // Given no authenticated token

        // When logout is attempted
        $response = $this->postJson('/api/v1/auth/logout');

        // Then the Sanctum guard rejects it
        $this->assertUnauthenticated($response);
    }

    // Edge — the revert-verified oracle: after logout the same token no longer
    // authenticates a protected route. Goes RED if currentAccessToken()->delete()
    // is removed, since a no-op logout would leave the token live.
    #[Test]
    public function when_a_user_has_logged_out_then_the_revoked_token_no_longer_authenticates(): void
    {
        // Given a registered user who has logged out with their token
        $token = $this->tokenFor($this->aRegisteredUser());
        $this->logoutWith($token);

        // When the same now-revoked token is presented to a protected route
        $response = $this->probeWith($token);

        // Then it no longer authenticates
        $this->assertUnauthenticated($response);
    }

    // Edge — logout revokes only the CURRENT session's token: the user's other
    // active sessions keep working. Distinguishes UC-1-004 from UC-1-011 logout-all
    // and catches a wrong tokens()->delete() that the single-token oracle would miss.
    #[Test]
    public function when_one_session_logs_out_then_the_users_other_sessions_remain_active(): void
    {
        // Given a user with two active sessions
        $user = $this->aRegisteredUser();
        $current = $this->tokenFor($user, 'web');
        $other = $this->tokenFor($user, 'mobile');

        // When they log out of the current session
        $this->logoutWith($current);

        // Then the current token is revoked but the other session still authenticates
        $this->assertUnauthenticated($this->probeWith($current));
        $this->probeWith($other)->assertOk()->assertJsonPath('user_id', $user->id);
    }

    // Happy — an authenticated user logs out and the request succeeds with no body.
    #[Test]
    public function when_an_authenticated_user_logs_out_then_the_session_ends(): void
    {
        // Given a registered user holding a live token
        $token = $this->tokenFor($this->aRegisteredUser());

        // When they log out
        $response = $this->logoutWith($token);

        // Then the request succeeds with no content
        $response->assertNoContent();
    }

    /**
     * Issue a real Sanctum token for $user and return its plain-text value. Logout
     * deletes the request's currentAccessToken, which only resolves to a real
     * PersonalAccessToken under a genuine Bearer token — Sanctum::actingAs yields a
     * TransientToken with no delete(), so the suite authenticates with real tokens.
     */
    private function tokenFor(User $user, string $name = 'web'): string
    {
        return $user->createToken($name)->plainTextToken;
    }

    private function logoutWith(string $token): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/auth/logout');
    }

    private function probeWith(string $token): TestResponse
    {
        // A probe must reflect the CURRENT token state, like a fresh client request.
        // Within one test the container reuses the RequestGuard, which caches the user
        // resolved by a prior request (e.g. the logout call) — forgetting guards forces
        // re-resolution from the DB so a revoked token is genuinely re-checked.
        Auth::forgetGuards();

        return $this->withToken($token)->getJson('/api/_test/authenticated-probe');
    }

    protected function assertUnauthenticated(TestResponse $response): void
    {
        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }
}
