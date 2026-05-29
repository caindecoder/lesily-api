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

    protected function exhaustLimit(string $uri, int $allowed): void
    {
        for ($i = 0; $i < $allowed; $i++) {
            $this->getJson($uri)->assertOk();
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
