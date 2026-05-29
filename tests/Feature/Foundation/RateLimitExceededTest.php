<?php

namespace Tests\Feature\Foundation;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

class RateLimitExceededTest extends FoundationTestCase
{
    #[Test]
    public function when_request_exceeds_general_api_limit_then_request_is_rejected_with_retry_guidance(): void
    {
        // Given a Free-tier user has consumed their general API quota for the minute
        $this->exhaustLimit('/api/_test/rate-limit-general-probe', 1);

        // When the user sends another general API request
        $response = $this->getJson('/api/_test/rate-limit-general-probe');

        // Then the request is rejected with a structured rate-limit error and retry guidance
        $this->assertRejectedWithRetryGuidance($response);
    }

    #[Test]
    public function when_unauthenticated_ip_exceeds_auth_endpoint_limit_then_request_is_rejected_with_retry_guidance(): void
    {
        // Given an unauthenticated client has exhausted the per-IP auth quota for the minute
        $this->exhaustLimit('/api/_test/rate-limit-auth-probe', 1, 'post');

        // When the client posts another login attempt
        $response = $this->postJson('/api/_test/rate-limit-auth-probe');

        // Then the same structured rate-limit error is returned for the auth endpoint
        $this->assertRejectedWithRetryGuidance($response);
    }

    protected function exhaustLimit(string $uri, int $allowed, string $method = 'get'): void
    {
        for ($i = 0; $i < $allowed; $i++) {
            $response = $method === 'post' ? $this->postJson($uri) : $this->getJson($uri);
            $response->assertOk();
        }
    }

    protected function assertRejectedWithRetryGuidance(TestResponse $response): void
    {
        $response->assertStatus(429);
        $response->assertJsonPath('error_code', 'RATE_LIMIT_EXCEEDED');

        $retryAfter = (int) $response->json('retry_after');
        $this->assertGreaterThan(0, $retryAfter, 'retry_after must tell the client how long to wait.');
        $this->assertSame((string) $retryAfter, $response->headers->get('Retry-After'));
        $this->assertNotNull($response->headers->get('X-RateLimit-Reset'));
        $this->assertNotEmpty($response->json('correlation_id'));
    }
}
