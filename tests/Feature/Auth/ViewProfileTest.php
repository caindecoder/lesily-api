<?php

namespace Tests\Feature\Auth;

use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ViewProfileTest extends AuthTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_no_token_provided_then_request_is_unauthenticated(): void
    {
        // Given no authentication token is provided

        // When a request is sent to GET /api/v1/me
        $response = $this->viewProfileWithoutAuth();

        // Then the request is rejected as unauthenticated
        $this->assertUnauthenticated($response);
    }
}
