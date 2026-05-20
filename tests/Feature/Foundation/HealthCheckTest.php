<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Foundation\Health\Services\HealthChecker;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * UC-1-097 — Health Check Endpoint (chunk-1 Tier-1 #1).
 *
 * Design refs:
 * - UC body: Documentation/06-design/chunk-1/use-cases/uc-infrastructure-security.md:31
 * - CD: Documentation/06-design/chunk-1/class-diagram/shared/cd-foundation-http.md (HealthCheckController, HealthChecker, HealthCheckResource, SubsystemStatus)
 * - API: Documentation/06-design/chunk-1/api-design/shared/api-1-infrastructure.md (GET /api/health)
 *
 * RED-phase: all tests fail because route + controller + service don't exist yet.
 */
final class HealthCheckTest extends TestCase
{
    // -------------------------------------------------------------
    // SAD PATH (per coding-conventions-plan §2.2: Sad → Edge → Happy)
    // -------------------------------------------------------------

    #[Test]
    public function when_database_unreachable_then_503_with_database_marked_down(): void
    {
        // Given the API is running
        // And the database is unreachable
        $response = $this->sendHealthCheckWithSubsystemDown('database');

        // When external service sends GET /api/health (in helper)
        // Then the response is 503
        // And the response body has status "unhealthy"
        // And the subsystems object shows database as "down"
        $this->assertSubsystemMarkedDown($response, 'database');
    }

    #[Test]
    public function when_redis_unreachable_then_503_with_redis_marked_down(): void
    {
        // Given the API is running
        // And Redis is unreachable
        $response = $this->sendHealthCheckWithSubsystemDown('redis');

        // When external service sends GET /api/health
        // Then the response is 503
        // And the subsystems object shows redis as "down"
        $this->assertSubsystemMarkedDown($response, 'redis');
    }

    #[Test]
    public function when_queue_not_processing_then_503_with_queue_marked_down(): void
    {
        // Given the API is running
        // And the queue worker is not processing
        $response = $this->sendHealthCheckWithSubsystemDown('queue');

        // When external service sends GET /api/health
        // Then the response is 503
        // And the subsystems object shows queue as "down"
        $this->assertSubsystemMarkedDown($response, 'queue');
    }

    // -------------------------------------------------------------
    // EDGE CASES
    // -------------------------------------------------------------

    #[Test]
    public function when_multiple_subsystems_down_then_503_lists_every_failure(): void
    {
        // Given the API is running
        // And both the database and Redis are unreachable
        $response = $this->sendHealthCheckWithMultipleSubsystemsDown(['database', 'redis']);

        // When external service sends GET /api/health
        // Then the response is 503
        // And the subsystems object marks every failed subsystem as "down"
        $this->assertMultipleSubsystemsMarkedDown($response, ['database', 'redis']);
    }

    #[Test]
    public function when_no_auth_token_provided_then_request_is_processed_normally(): void
    {
        // Given no authentication token is provided
        $response = $this->sendHealthCheckUnauthenticated();

        // When a request is sent to GET /api/health
        // Then the response is processed normally (not 401)
        $this->assertNotUnauthorized($response);
    }

    // -------------------------------------------------------------
    // HAPPY PATH
    // -------------------------------------------------------------

    #[Test]
    public function when_all_subsystems_healthy_then_200_with_all_up_and_checked_at(): void
    {
        // Given the API is running
        // And the database is reachable
        // And Redis is reachable
        // And the queue worker is processing
        $response = $this->sendHealthCheckWithAllSubsystemsHealthy();

        // When external service sends GET /api/health
        // Then the response is 200
        // And the response body has status "healthy"
        // And the response body has a checked_at ISO8601 timestamp
        // And the subsystems object shows app, database, redis, and queue all as "up"
        $this->assertAllSubsystemsHealthy($response);
    }

    // -------------------------------------------------------------
    // ACTION HELPERS (per coding-conventions-plan §2.2 — no raw HTTP calls in tests)
    // -------------------------------------------------------------

    protected function sendHealthCheckWithSubsystemDown(string $subsystem): TestResponse
    {
        $this->app->instance(HealthChecker::class, new HealthChecker([$subsystem => 'down']));

        return $this->getJson('/api/health');
    }

    /** @param  list<string>  $subsystems */
    protected function sendHealthCheckWithMultipleSubsystemsDown(array $subsystems): TestResponse
    {
        $this->app->instance(HealthChecker::class, new HealthChecker(array_fill_keys($subsystems, 'down')));

        return $this->getJson('/api/health');
    }

    protected function sendHealthCheckUnauthenticated(): TestResponse
    {
        // No acting-as / no auth header / no HealthChecker binding.
        // Endpoint is public — must return 200 or 503, never 401.
        return $this->getJson('/api/health');
    }

    protected function sendHealthCheckWithAllSubsystemsHealthy(): TestResponse
    {
        throw new \RuntimeException('Not implemented (RED phase): sendHealthCheckWithAllSubsystemsHealthy');
    }

    // -------------------------------------------------------------
    // ASSERTION HELPERS (per coding-conventions-plan §2.2 — grouped multi-aspect, max 3 params, 2-5 aspects)
    // -------------------------------------------------------------

    /** Verifies status 503 + JSON status "unhealthy" + subsystems.{name}=down (3 aspects). */
    protected function assertSubsystemMarkedDown(TestResponse $response, string $subsystem): void
    {
        $response->assertStatus(503);
        $response->assertJsonPath('status', 'unhealthy');
        $response->assertJsonPath("subsystems.{$subsystem}", 'down');
    }

    /**
     * Verifies status 503 + JSON status "unhealthy" + each failed subsystem marked down (3 aspects).
     *
     * @param  list<string>  $subsystems
     */
    protected function assertMultipleSubsystemsMarkedDown(TestResponse $response, array $subsystems): void
    {
        $response->assertStatus(503);
        $response->assertJsonPath('status', 'unhealthy');
        foreach ($subsystems as $subsystem) {
            $response->assertJsonPath("subsystems.{$subsystem}", 'down');
        }
    }

    /** Verifies response is NOT 401 (endpoint is public; 200 healthy or 503 unhealthy is acceptable). */
    protected function assertNotUnauthorized(TestResponse $response): void
    {
        $this->assertNotSame(401, $response->status(), 'Health check is public — must not return 401');
        $this->assertContains($response->status(), [200, 503], 'Response should be 200 (healthy) or 503 (unhealthy), not auth-blocked');
    }

    /** Verifies status 200 + status "healthy" + ISO8601 checked_at + all 4 subsystems "up" (4 aspects). */
    protected function assertAllSubsystemsHealthy(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertJsonPath('status', 'healthy');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            (string) $response->json('checked_at'),
            'checked_at must be ISO8601-formatted',
        );
        foreach (['app', 'database', 'redis', 'queue'] as $subsystem) {
            $response->assertJsonPath("subsystems.{$subsystem}", 'up');
        }
    }
}
