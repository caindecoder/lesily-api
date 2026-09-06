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

class EnsureIdempotencyKeyTest extends FoundationTestCase
{
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
    public function when_the_same_key_is_reused_with_a_different_query_string_then_it_is_rejected_as_a_key_conflict(): void
    {
        // Given a mutation already succeeded under an Idempotency-Key with one query string
        Event::fake();
        $key = 'a1b2c3d4-query-conflict';
        $this->sendRequiredMutationWithQuery($key, 'a=1')->assertCreated();

        // When the same key is reused with a different query string and the same body
        $response = $this->sendRequiredMutationWithQuery($key, 'a=2');

        // Then the request is rejected and the original side-effect is not repeated
        $this->assertRejectedAsKeyConflict($response);
    }

    #[Test]
    public function when_the_same_key_moves_its_input_from_query_to_body_then_it_is_rejected_as_a_key_conflict(): void
    {
        // Given a mutation already succeeded with its input in the query string and an empty body
        Event::fake();
        $key = 'a1b2c3d4-alias';
        $this->sendRawMutation($key, 'a=1', null)->assertCreated();

        // When the same key is reused with the identical bytes moved into the raw body
        $response = $this->sendRawMutation($key, '', 'a=1');

        // Then the two requests are distinguished and the reuse is rejected
        $this->assertRejectedAsKeyConflict($response);
    }

    #[Test]
    public function when_a_concurrent_duplicate_arrives_while_the_first_is_in_flight_then_it_is_rejected_as_already_processing(): void
    {
        // Given a first request already holds the reservation and is still in flight
        Event::fake();
        $key = 'a1b2c3d4-in-flight';
        $this->assertTrue(app(IdempotencyCache::class)->reserve($this->compositeKeyFor($key)));

        // When a concurrent request reuses the same key before the first completes
        $response = $this->sendRequiredMutation(key: $key);

        // Then it is rejected as already processing, without re-running the mutation
        $this->assertRejectedAsProcessing($response);
        Event::assertNotDispatched('probe.idempotent.executed');
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

        // Winner reserves, then completes — the remembered result carries the 24h TTL.
        /** @var Expectation $add */
        $add = $store->shouldReceive('add');
        $add->andReturnTrue();

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

    #[Test]
    public function when_the_first_attempt_fails_then_the_reservation_is_released_so_the_key_can_be_retried(): void
    {
        // Given a mutation that fails under an Idempotency-Key
        Event::fake();
        $key = 'a1b2c3d4-released';

        // When it is sent, fails, and is retried with the same key
        $first = $this->sendFailingMutation(key: $key);
        $second = $this->sendFailingMutation(key: $key);

        // Then the reservation was released, so the retry re-executes rather than being blocked
        $this->assertReattemptedAfterFailure($first, $second);
    }

    #[Test]
    public function when_an_attempt_throws_then_the_reservation_is_released_so_the_key_can_be_retried(): void
    {
        // Given a mutation that errors out (throws) under an Idempotency-Key
        Event::fake();
        $key = 'a1b2c3d4-threw';

        // When it is sent, errors, and is retried with the same key
        $first = $this->sendThrowingMutation(key: $key);
        $second = $this->sendThrowingMutation(key: $key);

        // Then the reservation was released, so the retry re-executes rather than being blocked
        $this->assertReattemptedAfterError($first, $second);
    }

    protected function sendRequiredMutation(?string $key = null, array $body = [], ?User $user = null): TestResponse
    {
        Sanctum::actingAs($user ?? $this->user);

        $headers = $key === null ? [] : ['Idempotency-Key' => $key];

        return $this->postJson('/api/_test/idempotent-required', $body, $headers);
    }

    protected function sendRequiredMutationWithQuery(string $key, string $query): TestResponse
    {
        Sanctum::actingAs($this->user);

        return $this->postJson('/api/_test/idempotent-required?'.$query, [], ['Idempotency-Key' => $key]);
    }

    protected function sendRawMutation(string $key, string $query, ?string $content): TestResponse
    {
        Sanctum::actingAs($this->user);

        $uri = '/api/_test/idempotent-required'.($query === '' ? '' : '?'.$query);
        $server = $this->transformHeadersToServerVars(['Idempotency-Key' => $key]);

        return $this->call('POST', $uri, [], [], [], $server, $content);
    }

    protected function sendOptionalMutation(?string $key = null): TestResponse
    {
        Sanctum::actingAs($this->user);

        $headers = $key === null ? [] : ['Idempotency-Key' => $key];

        return $this->patchJson('/api/_test/idempotent-optional', [], $headers);
    }

    protected function sendFailingMutation(?string $key = null): TestResponse
    {
        Sanctum::actingAs($this->user);

        $headers = $key === null ? [] : ['Idempotency-Key' => $key];

        return $this->postJson('/api/_test/idempotent-failing', [], $headers);
    }

    protected function sendThrowingMutation(?string $key = null): TestResponse
    {
        Sanctum::actingAs($this->user);

        $headers = $key === null ? [] : ['Idempotency-Key' => $key];

        return $this->postJson('/api/_test/idempotent-throwing', [], $headers);
    }

    protected function mockStoreUnavailable(): void
    {
        /** @var Repository&MockInterface $store */
        $store = Mockery::mock(Repository::class);

        // Reserve-first: the atomic add is the first store touch, so failing it
        // exercises the fail-closed path.
        /** @var Expectation $add */
        $add = $store->shouldReceive('add');
        $add->andThrow(new RuntimeException('Redis connection refused'));

        $this->app->instance(IdempotencyCache::class, new IdempotencyCache($store));
    }

    protected function mockStoreWriteFails(): void
    {
        /** @var Repository&MockInterface $store */
        $store = Mockery::mock(Repository::class);

        // Winner reserves successfully, then the result write (complete) fails.
        /** @var Expectation $add */
        $add = $store->shouldReceive('add');
        $add->andReturnTrue();

        /** @var Expectation $put */
        $put = $store->shouldReceive('put');
        $put->andThrow(new RuntimeException('Redis connection refused'));

        $this->app->instance(IdempotencyCache::class, new IdempotencyCache($store));
    }

    /**
     * The composite key the middleware computes for the required probe route —
     * reserving it through the real store is how a test occupies the
     * in-flight slot (the winner of the reservation race).
     */
    protected function compositeKeyFor(string $key): string
    {
        return sprintf(
            'idempotency:%s:POST:api/_test/idempotent-required:%s',
            $key,
            $this->user->getKey(),
        );
    }

    protected function assertRejectedAsMissingKey(TestResponse $response): void
    {
        $response->assertUnprocessable();
        $response->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_REQUIRED');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    protected function assertRefusedAsStoreUnavailable(TestResponse $response): void
    {
        $response->assertServiceUnavailable();
        $response->assertJsonPath('error_code', 'IDEMPOTENCY_CACHE_UNAVAILABLE');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    protected function assertRejectedAsKeyConflict(TestResponse $response): void
    {
        $response->assertConflict();
        $response->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_CONFLICT');
        Event::assertDispatchedTimes('probe.idempotent.executed', 1);
    }

    protected function assertRejectedAsProcessing(TestResponse $response): void
    {
        $response->assertConflict();
        $response->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_PROCESSING');
        $response->assertJsonPath('retry_after', 1);
        $response->assertHeader('Retry-After', '1');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    protected function assertReattemptedAfterFailure(TestResponse $first, TestResponse $second): void
    {
        $first->assertUnprocessable();
        $second->assertUnprocessable();
        Event::assertDispatchedTimes('probe.idempotent.executed', 2);
    }

    protected function assertReattemptedAfterError(TestResponse $first, TestResponse $second): void
    {
        $first->assertInternalServerError();
        $second->assertInternalServerError();
        Event::assertDispatchedTimes('probe.idempotent.executed', 2);
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
