<?php

namespace App\Foundation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Foundation\Http\Services\IdempotencyCache;
use App\Foundation\Http\Exceptions\IdempotencyKeyConflictException;
use App\Foundation\Http\Exceptions\IdempotencyKeyRequiredException;

/**
 * Mutation idempotency (UC-1-119) — applied per-route on POST/PATCH/DELETE. A
 * route opts in as `idempotency-key:required` or `idempotency-key:optional`.
 *
 * - Required route, no `Idempotency-Key` header → IdempotencyKeyRequiredException (422)
 * - Store unreachable                           → IdempotencyCacheUnavailableException (503, fail closed)
 * - Same key, different request body            → IdempotencyKeyConflictException (409)
 * - Cache miss                                  → process, then cache the response under the composite key
 *
 * Replaying a matching repeat is layered on in the final UC-1-119 cycle.
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

        $compositeKey = $this->compositeKey($request, $key);
        $requestHash = $this->requestHash($request);
        $cached = $this->cache->lookup($compositeKey);

        if ($cached !== null && $cached['request_hash'] !== $requestHash) {
            throw new IdempotencyKeyConflictException;
        }

        $response = $next($request);

        $this->cache->remember($compositeKey, $this->snapshot($response, $requestHash));

        return $response;
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

    private function requestHash(Request $request): string
    {
        return hash('sha256', $request->getContent());
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Response $response, string $requestHash): array
    {
        return [
            'request_hash' => $requestHash,
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getContent(),
        ];
    }
}
