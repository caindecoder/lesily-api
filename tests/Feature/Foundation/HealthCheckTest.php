<?php

namespace Tests\Feature\Foundation;

use Mockery;
use PDOException;
use RedisException;
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
        };
    }

    protected function mockSubsystemsHealthy(array $names): void
    {
        foreach ($names as $name) {
            match ($name) {
                'database' => $this->mockDatabaseUp(),
                'redis' => $this->mockRedisUp(),
                'queue' => $this->mockQueueUp(),
            };
        }
    }

    protected function mockDatabaseDown(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getPdo')->andThrow(new PDOException('connection refused'));
        DB::shouldReceive('connection')->andReturn($connection);
    }

    protected function mockDatabaseUp(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getPdo')->andReturn(Mockery::mock(\PDO::class));
        DB::shouldReceive('connection')->andReturn($connection);
    }

    protected function mockRedisDown(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('ping')->andThrow(new RedisException('connection refused'));
        Redis::shouldReceive('connection')->andReturn($connection);
    }

    protected function mockRedisUp(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('ping')->andReturn(true);
        Redis::shouldReceive('connection')->andReturn($connection);
    }

    protected function mockQueueDown(): void
    {
        Queue::shouldReceive('size')->andThrow(new \Exception('queue unreachable'));
    }

    protected function mockQueueUp(): void
    {
        Queue::shouldReceive('size')->andReturn(0);
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

    protected function mockAllSubsystemsHealthy(): void
    {
        $this->mockSubsystemsHealthy(['database', 'redis', 'queue']);
    }

    protected function assertResponseIsHealthy(TestResponse $response): void
    {
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'healthy');
    }
}
