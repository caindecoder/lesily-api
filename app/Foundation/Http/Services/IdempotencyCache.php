<?php

namespace App\Foundation\Http\Services;

use Throwable;
use Illuminate\Contracts\Cache\Repository;
use App\Foundation\Http\Exceptions\IdempotencyCacheUnavailableException;

/**
 * Dedup store for mutation idempotency (UC-1-119). Keyed by the composite
 * `idempotency:{key}:{method}:{path}:{auth-user-id}`, TTL 24h, on the
 * application's configured cache store. Production should point that store at an
 * atomic backend (e.g. Redis) per the design.
 *
 * The READ path fails closed — an unreachable store throws
 * IdempotencyCacheUnavailableException so the middleware rejects rather than risk
 * a duplicate side-effect. The WRITE path is best-effort: the mutation has
 * already run, so a failed cache write must never turn a success into an error.
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
        try {
            $entry = $this->store->get($compositeKey);
        } catch (Throwable) {
            throw new IdempotencyCacheUnavailableException;
        }

        return is_array($entry) ? $entry : null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function remember(string $compositeKey, array $entry): void
    {
        try {
            $this->store->put($compositeKey, $entry, self::TTL_SECONDS);
        } catch (Throwable) {
            // Best effort: the mutation already succeeded, so a failed cache write
            // must not surface as an error. A later retry may re-execute (no worse
            // than having no idempotency) rather than being wrongly rejected.
        }
    }
}
