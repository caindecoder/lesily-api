<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

/**
 * UC-1-098 — Rate Limit Exceeded Response (chunk-1 Tier-1 #3).
 *
 * Design refs:
 * - UC body: Documentation/06-design/chunk-1/use-cases/uc-infrastructure-security.md (UC-1-098)
 * - CD: Documentation/06-design/chunk-1/class-diagram/shared/cd-foundation-http.md (EmitRateLimitHeaders middleware)
 * - API: Documentation/06-design/chunk-1/api-design/shared/api-1-infrastructure.md (§4.2 RATE_LIMIT_EXCEEDED enum + §4.3 X-RateLimit-* headers)
 */
class RateLimitExceededTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/throttled-probe', function (Request $request) {
            throw new ThrottleRequestsException(
                'Too many requests.',
                null,
                ['Retry-After' => 32],
            );
        });

        Route::post('/_test/throttled-auth-probe', function (Request $request) {
            throw new ThrottleRequestsException(
                'Too many requests.',
                null,
                ['Retry-After' => 6],
            );
        });
    }

    #[Test]
    public function when_free_user_exceeds_general_api_limit_then_responds_with_throttled_envelope(): void
    {
        // Given a Free-tier user has already used their general API quota for the minute
        // When the user sends another general API request
        $response = $this->sendRequestExceedingFreeTierGeneralApiLimit();

        // Then the response envelope is RATE_LIMIT_EXCEEDED with Retry-After
        $this->assertThrottledEnvelope($response);
    }

    #[Test]
    public function when_unauth_ip_exceeds_auth_endpoint_limit_then_responds_with_throttled_envelope(): void
    {
        // Given an unauthenticated client has exhausted the per-IP auth quota for the minute
        // When the client posts another login attempt
        $response = $this->sendRequestExceedingAuthEndpointLimit();

        // Then the same RATE_LIMIT_EXCEEDED envelope is returned (shorter Retry-After per tighter window)
        $this->assertThrottledEnvelope($response);
    }

    protected function sendRequestExceedingFreeTierGeneralApiLimit(): TestResponse
    {
        return $this->getJson('/_test/throttled-probe');
    }

    protected function sendRequestExceedingAuthEndpointLimit(): TestResponse
    {
        return $this->postJson('/_test/throttled-auth-probe');
    }

    protected function assertThrottledEnvelope(TestResponse $response): void
    {
        $response->assertStatus(429);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('error_code', 'RATE_LIMIT_EXCEEDED');
        $response->assertJsonStructure(['retry_after', 'correlation_id']);
        $this->assertSame(
            (int) $response->json('retry_after'),
            (int) $response->headers->get('Retry-After'),
        );
    }
}
