<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Http\Services\IdempotencyCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\Http\Exceptions\IdempotencyCacheUnavailableException;

class EnsureIdempotencyKeyTest extends FoundationTestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();

        // Error-path render parity (amendment M): APP_DEBUG=true (the local
        // default) masks the prod render path. Idempotency rejections must
        // render their mapped envelopes the same way CI/prod do.
        config(['app.debug' => false]);
    }

    #[Test]
    public function when_required_route_is_called_without_an_idempotency_key_then_the_request_is_rejected_as_missing(): void
    {
        // Given an authenticated user calling a route that requires an Idempotency-Key

        // When the request omits the Idempotency-Key header
        $response = $this->sendRequiredMutation(key: null);

        // Then the request is rejected as missing the required key
        $this->assertRejectedAsMissingKey($response);
    }

    #[Test]
    public function when_the_idempotency_store_is_unreachable_on_a_required_route_then_the_request_is_refused(): void
    {
        // Given the idempotency store is unreachable
        $this->mockStoreUnavailable();

        // When an authenticated user sends a mutation carrying a valid key
        $response = $this->sendRequiredMutation(key: 'a1b2c3d4-store-down');

        // Then the request is refused rather than risking a duplicate side-effect
        $this->assertRefusedAsStoreUnavailable($response);
    }

    protected function sendRequiredMutation(?string $key = null, array $body = [], ?User $user = null): TestResponse
    {
        Sanctum::actingAs($user ?? $this->user);

        $headers = $key === null ? [] : ['Idempotency-Key' => $key];

        return $this->postJson('/api/_test/idempotent-required', $body, $headers);
    }

    protected function mockStoreUnavailable(): void
    {
        $this->mock(IdempotencyCache::class)
            ->shouldReceive('lookup')
            ->andThrow(new IdempotencyCacheUnavailableException);
    }

    protected function assertRejectedAsMissingKey(TestResponse $response): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_REQUIRED');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    protected function assertRefusedAsStoreUnavailable(TestResponse $response): void
    {
        $response->assertStatus(503);
        $response->assertJsonPath('error_code', 'IDEMPOTENCY_CACHE_UNAVAILABLE');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }
}
