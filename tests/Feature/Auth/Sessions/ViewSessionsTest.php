<?php

namespace Tests\Feature\Auth\Sessions;

use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ViewSessionsTest extends AuthTestCase
{
    use RefreshDatabase;

    // Sad — the session-list endpoint is guarded: a request with no token is
    // rejected by the Sanctum guard before any listing runs (proves auth:sanctum).
    #[Test]
    public function when_no_token_is_presented_then_the_session_list_is_rejected(): void
    {
        // Given no authenticated token

        // When the active sessions are requested
        $response = $this->getJson('/api/v1/me/sessions');

        // Then the Sanctum guard rejects it
        $this->assertUnauthenticated($response);
    }
}
