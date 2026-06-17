<?php

namespace Tests\Feature\Foundation;

use Mockery;
use App\Models\User;
use RuntimeException;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Contracts\Cache\Repository;
use App\Foundation\Http\Services\IdempotencyCache;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    #[Test]
    public function when_the_same_key_is_reused_for_a_different_request_then_it_is_rejected_as_a_key_conflict(): void
    {
        // Given a mutation already succeeded under an Idempotency-Key
        Event::fake();
        $key = 'a1b2c3d4-conflict';
        $this->sendRequiredMutation(key: $key, body: ['type' => 'access'])->assertCreated();

        // When the same key is reused with a different request body
        $response = $this->sendRequiredMutation(key: $key, body: ['type' => 'deletion']);

        // Then the request is rejected and the original side-effect is not repeated
        $this->assertRejectedAsKeyConflict($response);
    }

    #[Test]
    public function when_an_optional_route_is_called_without_a_key_then_the_request_is_processed_without_caching(): void
    {
        // Given a route where the Idempotency-Key is optional and the client omits it

        // When the user sends the mutation
        $response = $this->sendOptionalMutation();

        // Then it is processed normally, with no replay guarantee
        $this->assertProcessedWithoutReplay($response);
    }

    protected function sendRequiredMutation(?string $key = null, array $body = [], ?User $user = null): TestResponse
    {
        Sanctum::actingAs($user ?? $this->user);

        $headers = $key === null ? [] : ['Idempotency-Key' => $key];

        return $this->postJson('/api/_test/idempotent-required', $body, $headers);
    }

    protected function sendOptionalMutation(?string $key = null): TestResponse
    {
        Sanctum::actingAs($this->user);

        $headers = $key === null ? [] : ['Idempotency-Key' => $key];

        return $this->patchJson('/api/_test/idempotent-optional', [], $headers);
    }

    protected function mockStoreUnavailable(): void
    {
        $store = Mockery::mock(Repository::class);
        $store->shouldReceive('get')->andThrow(new RuntimeException('Redis connection refused'));

        $this->app->instance(IdempotencyCache::class, new IdempotencyCache($store));
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

    protected function assertRejectedAsKeyConflict(TestResponse $response): void
    {
        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_CONFLICT');
        Event::assertDispatchedTimes('probe.idempotent.executed', 1);
    }

    protected function assertProcessedWithoutReplay(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertHeaderMissing('Idempotency-Replayed');
        $response->assertJsonStructure(['id']);
    }
}
