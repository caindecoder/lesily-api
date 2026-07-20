<?php

namespace Tests\Feature\Foundation;

use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Http\Services\IdempotencyCache;

/**
 * @testclass-carveout: infra-primitive — crash-recovery TTL expiry is
 * unreachable via HTTP (a request cannot crash mid-flight in-process;
 * OQ-1-114 R2 precedent).
 */
class IdempotencyCacheTest extends FoundationTestCase
{
    #[Test]
    public function when_a_pending_reservation_expires_then_the_key_can_be_reserved_again(): void
    {
        // Given a key reserved by a request that then crashed — never completing or releasing it
        $cache = $this->app->make(IdempotencyCache::class);
        $key = 'idempotency:crashed:POST:probe:1';
        $this->assertTrue($cache->reserve($key));

        // When the short reservation TTL elapses
        $this->travel(61)->seconds();

        // Then the key is reservable again — a crashed reservation cannot block forever
        $this->assertTrue($cache->reserve($key));
    }
}
