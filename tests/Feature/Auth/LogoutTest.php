<?php

namespace Tests\Feature\Auth;

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

    protected function assertUnauthenticated(TestResponse $response): void
    {
        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }
}
