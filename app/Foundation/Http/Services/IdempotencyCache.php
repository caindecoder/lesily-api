<?php

namespace App\Foundation\Http\Services;

use Throwable;
use Illuminate\Contracts\Cache\Repository;
use App\Foundation\Http\Exceptions\IdempotencyCacheUnavailableException;

/**
 * Dedup store for mutation idempotency (UC-1-119 + OQ-1-114 atomic reservation).
 * Keyed by the composite `idempotency:{key}:{method}:{path}:{auth-user-id}` on
 * the application's configured cache store. Production should point that store at
 * an atomic backend (e.g. Redis) per the design (OQ-1-115).
 *
 * One entry per key carries a `state`: a short-lived `pending` reservation
 * (PROCESSING for concurrent duplicates) promoted to a `done` result held for the
 * 24h dedup window. reserve() is atomic put-if-absent — exactly one concurrent
 * caller wins. The READ + reserve paths fail closed (an unreachable store throws
 * so the middleware rejects rather than risk a duplicate); complete() is
 * best-effort (the mutation already ran, so a failed write must not error).
 */
class IdempotencyCache
{
    private const int RESERVATION_TTL_SECONDS = 60;

    private const int RESULT_TTL_SECONDS = 86400;

    public function __construct(private readonly Repository $store) {}

    /**
     * Atomically claim the key for processing (put-if-absent). Returns true to
     * exactly one concurrent caller — the winner; duplicates get false. Fails
     * closed: an unreachable store throws so the middleware rejects rather than
     * risk a duplicate side-effect. The short TTL is the crash safety-net — a
     * winner that dies before completing leaves a reservation that self-expires.
     */
    public function reserve(string $compositeKey): bool
    {
        try {
            return $this->store->add($compositeKey, ['state' => 'pending'], self::RESERVATION_TTL_SECONDS);
        } catch (Throwable) {
            throw new IdempotencyCacheUnavailableException;
        }
    }

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
     * Promote the winner's reservation to the remembered result (pending → done),
     * held for the 24h dedup window. Best-effort: the mutation already succeeded,
     * so a failed cache write must not surface as an error — a later retry may
     * re-execute (no worse than having no idempotency) rather than be rejected.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function complete(string $compositeKey, array $snapshot): void
    {
        try {
            $this->store->put($compositeKey, ['state' => 'done', ...$snapshot], self::RESULT_TTL_SECONDS);
        } catch (Throwable) {
            // Best effort (see the reserve() docblock).
        }
    }
}
