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

    public function handle(Request $request, Closure $next, string $mode): Response
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

        if ($cached !== null) {
            if ($cached['request_hash'] !== $requestHash) {
                throw new IdempotencyKeyConflictException;
            }

            return $this->replay($cached);
        }

        $response = $next($request);

        $this->cache->remember($compositeKey, $this->snapshot($response, $requestHash));

        return $response;
    }

    private function compositeKey(Request $request, string $key): string
    {
        return sprintf(
            'idempotency:%s:%s:%s:%s',
            $key,
            $request->method(),
            $request->path(),
            (string) $request->user()?->getAuthIdentifier(),
        );
    }

    private function requestHash(Request $request): string
    {
        return hash('sha256', (string) $request->getQueryString().'|'.$request->getContent());
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
            'content_type' => (string) $response->headers->get('Content-Type'),
        ];
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    private function replay(array $cached): Response
    {
        return response((string) $cached['body'], (int) $cached['status'])
            ->header('Content-Type', (string) $cached['content_type'])
            ->header('Idempotency-Replayed', 'true');
    }
}
