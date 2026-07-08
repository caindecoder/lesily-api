<?php

namespace Tests\Feature\Auth;

use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ListMyCompaniesTest extends AuthTestCase
{
    use RefreshDatabase;

    // Sad — the switcher list is guarded: a request with no token is rejected by
    // the Sanctum guard before any listing runs (proves auth:sanctum, UC-1-034).
    #[Test]
    public function when_request_has_no_token_then_unauthenticated(): void
    {
        // Given no authenticated token

        // When the companies list is requested
        $response = $this->getJson('/api/v1/me/companies');

        // Then the Sanctum guard rejects it
        $this->assertUnauthenticated($response);
    }
}
