<?php

namespace Tests\Feature\Foundation;

use Mockery;
use App\Models\User;
use RuntimeException;
use Mockery\Expectation;
use Mockery\MockInterface;
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
    public function when_the_store_fails_after_a_mutation_succeeds_then_the_response_is_still_returned(): void
    {
        // Given the store misses on lookup but fails on the write afterwards
        $this->mockStoreWriteFails();

        // When the mutation is processed and the cache write fails
        $response = $this->sendRequiredMutation(key: 'a1b2c3d4-write-fail');

        // Then the already-successful mutation is returned, not turned into an error
        $response->assertCreated();
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

    #[Test]
    public function when_two_users_send_the_same_key_then_each_request_is_processed_independently(): void
    {
        // Given two authenticated users sharing one Idempotency-Key
        Event::fake();
        $key = 'shared-key-1234';
        $otherUser = User::factory()->create();

        // When both send a mutation with that key
        $first = $this->sendRequiredMutation(key: $key, user: $this->user);
        $second = $this->sendRequiredMutation(key: $key, user: $otherUser);

        // Then each request is processed independently, not replayed against the other
        $this->assertBothProcessedIndependently($first, $second);
    }

    #[Test]
    public function when_the_same_key_is_used_on_two_different_routes_then_each_is_processed_independently(): void
    {
        // Given one Idempotency-Key reused across two different routes
        Event::fake();
        $key = 'cross-route-key';

        // When the user sends it to the required (POST) route and the optional (PATCH) route
        $required = $this->sendRequiredMutation(key: $key);
        $optional = $this->sendOptionalMutation(key: $key);

        // Then each route processes independently — no cross-route replay
        $this->assertProcessedOnDistinctRoutes($required, $optional);
    }

    #[Test]
    public function when_a_first_request_carries_a_new_key_then_it_is_processed_and_its_outcome_is_remembered(): void
    {
        // Given a fresh Idempotency-Key never seen before
        Event::fake();

        // When the user sends the mutation
        $response = $this->sendRequiredMutation(key: 'a1b2c3d4-fresh');

        // Then it is processed once, with no replay
        $this->assertProcessedFreshly($response);
    }

    #[Test]
    public function when_a_fresh_request_is_cached_then_it_is_stored_for_the_24h_dedup_window(): void
    {
        // Given a cache store whose write we can observe
        /** @var Repository&MockInterface $store */
        $store = Mockery::mock(Repository::class);

        /** @var Expectation $get */
        $get = $store->shouldReceive('get');
        $get->andReturnNull();

        /** @var Expectation $put */
        $put = $store->shouldReceive('put');
        $put->once()->with(Mockery::type('string'), Mockery::type('array'), 86400);

        $this->app->instance(IdempotencyCache::class, new IdempotencyCache($store));

        // When a fresh mutation is processed
        $response = $this->sendRequiredMutation(key: 'a1b2c3d4-ttl');

        // Then it is cached with a 24-hour TTL
        $response->assertCreated();
    }

    #[Test]
    public function when_a_processed_key_is_retried_then_the_original_outcome_is_replayed_without_repeating_side_effects(): void
    {
        // Given a mutation already succeeded under an Idempotency-Key
        Event::fake();
        $key = 'a1b2c3d4-replay';
        $body = ['type' => 'access'];
        $first = $this->sendRequiredMutation(key: $key, body: $body);
        $first->assertCreated();

        // When the identical request is retried with the same key
        $replay = $this->sendRequiredMutation(key: $key, body: $body);

        // Then the original response is replayed and the side-effect is not repeated
        $this->assertReplayed($first, $replay);
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
        /** @var Repository&MockInterface $store */
        $store = Mockery::mock(Repository::class);

        /** @var Expectation $get */
        $get = $store->shouldReceive('get');
        $get->andThrow(new RuntimeException('Redis connection refused'));

        $this->app->instance(IdempotencyCache::class, new IdempotencyCache($store));
    }

    protected function mockStoreWriteFails(): void
    {
        /** @var Repository&MockInterface $store */
        $store = Mockery::mock(Repository::class);

        /** @var Expectation $get */
        $get = $store->shouldReceive('get');
        $get->andReturnNull();

        /** @var Expectation $put */
        $put = $store->shouldReceive('put');
        $put->andThrow(new RuntimeException('Redis connection refused'));

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

    protected function assertReplayed(TestResponse $first, TestResponse $replay): void
    {
        $replay->assertCreated();
        $replay->assertHeader('Idempotency-Replayed', 'true');
        $replay->assertHeader('Content-Type', $first->headers->get('Content-Type'));
        $this->assertSame($first->json('id'), $replay->json('id'));
        Event::assertDispatchedTimes('probe.idempotent.executed', 1);
    }

    protected function assertBothProcessedIndependently(TestResponse $first, TestResponse $second): void
    {
        $first->assertCreated();
        $second->assertCreated();
        $this->assertNotSame($first->json('id'), $second->json('id'));
        Event::assertDispatchedTimes('probe.idempotent.executed', 2);
    }

    protected function assertProcessedOnDistinctRoutes(TestResponse $required, TestResponse $optional): void
    {
        $required->assertCreated();
        $optional->assertOk();
        $this->assertNotSame($required->json('id'), $optional->json('id'));
        Event::assertDispatchedTimes('probe.idempotent.executed', 2);
    }

    protected function assertProcessedFreshly(TestResponse $response): void
    {
        $response->assertCreated();
        $response->assertHeaderMissing('Idempotency-Replayed');
        Event::assertDispatchedTimes('probe.idempotent.executed', 1);
    }
}
