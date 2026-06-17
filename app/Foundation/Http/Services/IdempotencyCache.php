<?php

namespace App\Foundation\Http\Services;

/**
 * Redis-backed dedup store for mutation idempotency (UC-1-119). Keyed by the
 * composite `idempotency:{key}:{route}:{auth-user-id}`, TTL 24h.
 *
 * Throws IdempotencyCacheUnavailableException when the store is unreachable so
 * the middleware fails closed. Lookup returns null on a cache miss; the
 * Redis-backed read/write is layered on in later UC-1-119 cycles.
 */
class IdempotencyCache
{
    /**
     * @return array<string, mixed>|null
     */
    public function lookup(string $compositeKey): ?array
    {
        return null;
    }
}
