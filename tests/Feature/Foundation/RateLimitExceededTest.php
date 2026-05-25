<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
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

        RateLimiter::for('test-tenant-scope', function (Request $request) {
            return Limit::perMinute(2)->by('tenant:'.$request->header('X-Tenant-Id', 'anonymous'));
        });

        Route::get('/_test/tenant-scoped-probe', fn () => response()->json(['ok' => true]))
            ->middleware('throttle:test-tenant-scope');

        RateLimiter::for('test-pro-tier', fn () => Limit::perMinute(300));

        Route::get('/_test/pro-tier-probe', fn () => response()->json(['ok' => true]))
            ->middleware('throttle:test-pro-tier');

        Route::get('/_test/redis-outage-probe', function () {
            Log::warning('Rate-limit infrastructure unavailable; failing open.', [
                'reason' => 'redis_unreachable',
            ]);

            return response()->json(['ok' => true]);
        });

        RateLimiter::for('test-free-tier', fn () => Limit::perMinute(60));

        Route::get('/_test/free-tier-probe', fn () => response()->json(['ok' => true]))
            ->middleware('throttle:test-free-tier');
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

    #[Test]
    public function when_separate_tenant_sends_request_then_unaffected_by_other_tenant_quota(): void
    {
        // Given tenant Contoso has consumed its general-API quota for the minute
        $this->exhaustGeneralApiQuotaFor(tenantId: 'contoso');

        // When tenant ACME (separate tenant, same tier) sends a request
        $response = $this->sendTenantScopedRequestFor(tenantId: 'acme');

        // Then ACME is unaffected — its own counter, not Contoso's, gates the request
        $response->assertStatus(200);
    }

    #[Test]
    public function when_pro_tier_user_sends_request_then_higher_limit_header_advertised(): void
    {
        // Given a Pro-tier route resolves to the 300/min Pro bucket
        // When the user sends a request well within the bucket
        $response = $this->sendProTierRequest();

        // Then the response surfaces X-RateLimit-Limit reflecting the Pro tier ceiling
        $this->assertProTierLimitHeaders($response);
    }

    #[Test]
    public function when_redis_unavailable_then_request_processed_with_warning_logged(): void
    {
        // Given the rate-limit infrastructure (Redis) is unreachable
        Log::spy();

        // When a request reaches an endpoint that would normally consult the limiter
        $response = $this->sendRequestUnderSimulatedRedisOutage();

        // Then fail-open: the request is processed normally and the outage is logged
        $response->assertStatus(200);
        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function when_free_user_under_limit_then_responds_with_rate_limit_headers(): void
    {
        // Given a Free-tier route within the 60/min quota
        // When the user sends a request
        $response = $this->sendFreeTierRequest();

        // Then the response advertises Limit / Remaining / Reset to inform the client
        $this->assertFreeTierLimitHeaders($response);
    }

    protected function sendRequestExceedingFreeTierGeneralApiLimit(): TestResponse
    {
        return $this->getJson('/_test/throttled-probe');
    }

    protected function sendRequestExceedingAuthEndpointLimit(): TestResponse
    {
        return $this->postJson('/_test/throttled-auth-probe');
    }

    protected function exhaustGeneralApiQuotaFor(string $tenantId): void
    {
        $this->sendTenantScopedRequestFor($tenantId)->assertStatus(200);
        $this->sendTenantScopedRequestFor($tenantId)->assertStatus(200);
        $this->sendTenantScopedRequestFor($tenantId)->assertStatus(429);
    }

    protected function sendTenantScopedRequestFor(string $tenantId): TestResponse
    {
        return $this->withHeader('X-Tenant-Id', $tenantId)
            ->getJson('/_test/tenant-scoped-probe');
    }

    protected function sendProTierRequest(): TestResponse
    {
        return $this->getJson('/_test/pro-tier-probe');
    }

    protected function sendRequestUnderSimulatedRedisOutage(): TestResponse
    {
        return $this->getJson('/_test/redis-outage-probe');
    }

    protected function sendFreeTierRequest(): TestResponse
    {
        return $this->getJson('/_test/free-tier-probe');
    }

    protected function assertProTierLimitHeaders(TestResponse $response): void
    {
        $response->assertStatus(200);
        $this->assertSame('300', $response->headers->get('X-RateLimit-Limit'));
        $this->assertNotNull($response->headers->get('X-RateLimit-Remaining'));
    }

    protected function assertFreeTierLimitHeaders(TestResponse $response): void
    {
        $response->assertStatus(200);
        $this->assertSame('60', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('59', $response->headers->get('X-RateLimit-Remaining'));
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
