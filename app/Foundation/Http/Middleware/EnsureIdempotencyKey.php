<?php

namespace App\Foundation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Foundation\Http\Services\IdempotencyCache;
use App\Foundation\Http\Exceptions\IdempotencyKeyRequiredException;

/**
 * Mutation idempotency (UC-1-119) — applied per-route on POST/PATCH/DELETE. A
 * route opts in as `idempotency-key:required` or `idempotency-key:optional`.
 *
 * - Required route, no `Idempotency-Key` header  → IdempotencyKeyRequiredException (422)
 * - Store unreachable                            → IdempotencyCacheUnavailableException (503, fail closed)
 *
 * Lookup/replay and conflict detection are layered on in later UC-1-119 cycles.
 */
class EnsureIdempotencyKey
{
    public function __construct(private readonly IdempotencyCache $cache) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($mode === 'required' && $key === null) {
            throw new IdempotencyKeyRequiredException;
        }

        if ($key === null) {
            // Optional route without a key — one-shot mutation, no replay guarantee.
            return $next($request);
        }

        $this->cache->lookup($this->compositeKey($request, $key));

        return $next($request);
    }

    private function compositeKey(Request $request, string $key): string
    {
        return sprintf(
            'idempotency:%s:%s:%s',
            $key,
            (string) $request->route()?->uri(),
            (string) $request->user()?->getAuthIdentifier(),
        );
    }
}
