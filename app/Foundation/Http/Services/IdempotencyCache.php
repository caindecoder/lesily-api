<?php

namespace App\Foundation\Http\Services;

use Closure;
use Throwable;
use Illuminate\Contracts\Cache\Repository;
use App\Foundation\Http\Exceptions\IdempotencyCacheUnavailableException;

/**
 * Dedup store for mutation idempotency (UC-1-119). Keyed by the composite
 * `idempotency:{key}:{route}:{auth-user-id}`, TTL 24h. Backed by the framework
 * cache repository (point at Redis in production per the design).
 *
 * Any store failure is translated into IdempotencyCacheUnavailableException so
 * the middleware can fail closed rather than risk a duplicate side-effect.
 */
class IdempotencyCache
{
    private const int TTL_SECONDS = 86400;

    public function __construct(private readonly Repository $store) {}

    /**
     * @return array<string, mixed>|null
     */
    public function lookup(string $compositeKey): ?array
    {
        return $this->guarded(function () use ($compositeKey): ?array {
            $entry = $this->store->get($compositeKey);

            return is_array($entry) ? $entry : null;
        });
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function remember(string $compositeKey, array $entry): void
    {
        $this->guarded(fn () => $this->store->put($compositeKey, $entry, self::TTL_SECONDS));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function guarded(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable) {
            throw new IdempotencyCacheUnavailableException;
        }
    }
}
