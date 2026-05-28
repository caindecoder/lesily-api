<?php

namespace Tests\Feature\Foundation;

use PDO;
use Mockery;
use Exception;
use PDOException;
use RedisException;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

class HealthCheckTest extends FoundationTestCase
{
    #[Test]
    #[DataProvider('provideSingleSubsystemDown')]
    public function when_one_subsystem_is_unavailable_then_response_marks_only_that_subsystem_down(string $subsystem): void
    {
        // Given
        $this->mockSubsystemDown($subsystem);
        $this->mockSubsystemsHealthy(array_diff(['database', 'redis', 'queue'], [$subsystem]));

        // When
        $response = $this->getJson('/api/health');

        // Then
        $this->assertResponseIsUnhealthy($response);
        $this->assertSubsystemDown($response, $subsystem);
        $this->assertOtherSubsystemsUp($response, $subsystem);
    }

    #[Test]
    public function when_multiple_subsystems_are_unavailable_then_response_marks_each_failed_subsystem_down(): void
    {
        // Given
        $this->mockSubsystemDown('database');
        $this->mockSubsystemDown('redis');
        $this->mockSubsystemsHealthy(['queue']);

        // When
        $response = $this->getJson('/api/health');

        // Then
        $this->assertResponseIsUnhealthy($response);
        $this->assertSubsystemsDown($response, ['database', 'redis']);
        $this->assertSubsystemsUp($response, ['app', 'queue']);
    }

    #[Test]
    public function when_request_is_unauthenticated_then_response_is_processed_normally(): void
    {
        // Given
        $this->mockAllSubsystemsHealthy();

        // When (no authentication setup — public endpoint)
        $response = $this->getJson('/api/health');

        // Then
        $this->assertResponseIsHealthy($response);
    }

    #[Test]
    public function when_all_subsystems_are_healthy_then_response_marks_everything_up(): void
    {
        // Given
        $this->mockAllSubsystemsHealthy();

        // When
        $response = $this->getJson('/api/health');

        // Then
        $this->assertResponseIsHealthy($response);
        $this->assertAllSubsystemsUp($response);
        $this->assertCheckedAtIsIso8601WithZ($response);
    }

    public static function provideSingleSubsystemDown(): array
    {
        return [
            'database down' => ['database'],
            'redis down' => ['redis'],
            'queue down' => ['queue'],
        ];
    }

    protected function mockSubsystemDown(string $name): void
    {
        match ($name) {
            'database' => $this->mockDatabaseDown(),
            'redis' => $this->mockRedisDown(),
            'queue' => $this->mockQueueDown(),
            default => throw new InvalidArgumentException("Unknown subsystem: {$name}"),
        };
    }

    protected function mockSubsystemsHealthy(array $names): void
    {
        foreach ($names as $name) {
            match ($name) {
                'database' => $this->mockDatabaseUp(),
                'redis' => $this->mockRedisUp(),
                'queue' => $this->mockQueueUp(),
                default => throw new InvalidArgumentException("Unknown subsystem: {$name}"),
            };
        }
    }

    protected function mockAllSubsystemsHealthy(): void
    {
        $this->mockSubsystemsHealthy(['database', 'redis', 'queue']);
    }

    protected function mockDatabaseDown(): void
    {
        DB::shouldReceive('connection->getPdo')
            ->andThrow(new PDOException('connection refused'));
    }

    protected function mockDatabaseUp(): void
    {
        DB::shouldReceive('connection->getPdo')
            ->andReturn(Mockery::mock(PDO::class));
    }

    protected function mockRedisDown(): void
    {
        Redis::shouldReceive('connection->ping')
            ->andThrow(new RedisException('connection refused'));
    }

    protected function mockRedisUp(): void
    {
        Redis::shouldReceive('connection->ping')
            ->andReturn(true);
    }

    protected function mockQueueDown(): void
    {
        Queue::shouldReceive('size')->andThrow(new Exception('queue unreachable'));
    }

    protected function mockQueueUp(): void
    {
        Queue::shouldReceive('size')->andReturn(0);
    }

    protected function assertResponseIsHealthy(TestResponse $response): void
    {
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'healthy');
    }

    protected function assertResponseIsUnhealthy(TestResponse $response): void
    {
        $response->assertStatus(503);
        $response->assertJsonPath('status', 'unhealthy');
    }

    protected function assertSubsystemDown(TestResponse $response, string $name): void
    {
        $response->assertJsonPath("subsystems.{$name}", 'down');
    }

    protected function assertOtherSubsystemsUp(TestResponse $response, string $downName): void
    {
        $others = array_diff(['app', 'database', 'redis', 'queue'], [$downName]);
        foreach ($others as $name) {
            $response->assertJsonPath("subsystems.{$name}", 'up');
        }
    }

    protected function assertSubsystemsDown(TestResponse $response, array $names): void
    {
        foreach ($names as $name) {
            $response->assertJsonPath("subsystems.{$name}", 'down');
        }
    }

    protected function assertSubsystemsUp(TestResponse $response, array $names): void
    {
        foreach ($names as $name) {
            $response->assertJsonPath("subsystems.{$name}", 'up');
        }
    }

    protected function assertAllSubsystemsUp(TestResponse $response): void
    {
        foreach (['app', 'database', 'redis', 'queue'] as $name) {
            $response->assertJsonPath("subsystems.{$name}", 'up');
        }
    }

    protected function assertCheckedAtIsIso8601WithZ(TestResponse $response): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $response->json('checked_at')
        );
    }
}
