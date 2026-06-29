<?php

namespace Tests\Feature\Auth\Sessions;

use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
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
}
