<?php

namespace App\Foundation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Foundation\Http\Services\IdempotencyCache;
use App\Foundation\Http\Exceptions\IdempotencyKeyConflictException;
use App\Foundation\Http\Exceptions\IdempotencyKeyRequiredException;
use App\Foundation\Http\Exceptions\IdempotencyKeyProcessingException;

/**
 * Mutation idempotency (UC-1-119 + OQ-1-114) — applied per-route on
 * POST/PATCH/DELETE. A route opts in as `idempotency-key:required` or
 * `idempotency-key:optional`.
 *
 * Reserve-first (atomic put-if-absent) closes the concurrent check-then-act race:
 * - Required route, no `Idempotency-Key` header → IdempotencyKeyRequiredException (422)
 * - Store unreachable                           → IdempotencyCacheUnavailableException (503, fail closed)
 * - Reservation won (winner)                    → process, then remember the response (pending → done)
 * - Reservation lost, first still in flight     → IdempotencyKeyProcessingException (409 + Retry-After)
 * - Reservation lost, completed, same body      → replay the remembered response
 * - Reservation lost, completed, different body → IdempotencyKeyConflictException (409)
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

        if (! $this->cache->reserve($compositeKey)) {
            return $this->resolveExisting($compositeKey, $requestHash);
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            $this->cache->complete($compositeKey, $this->snapshot($response, $requestHash));
        }

        return $response;
    }

    /**
     * The reservation was already held — branch on the existing entry. A still
     * in-flight (pending) reservation, or one that expired in the race gap, is
     * told to retry; a completed entry replays for a matching body or conflicts
     * for a different one.
     */
    private function resolveExisting(string $compositeKey, string $requestHash): Response
    {
        $entry = $this->cache->lookup($compositeKey);

        if ($entry === null || $entry['state'] === 'pending') {
            throw new IdempotencyKeyProcessingException;
        }

        if ($entry['request_hash'] !== $requestHash) {
            throw new IdempotencyKeyConflictException;
        }

        return $this->replay($entry);
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
