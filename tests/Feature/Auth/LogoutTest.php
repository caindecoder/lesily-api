<?php

namespace Tests\Feature\Auth;

use App\Models\User;
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

    protected function assertUnauthenticated(TestResponse $response): void
    {
        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }
}
