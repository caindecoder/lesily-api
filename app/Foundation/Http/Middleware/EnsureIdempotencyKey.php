<?php

namespace App\Foundation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Foundation\Http\Exceptions\IdempotencyKeyRequiredException;

/**
 * Mutation idempotency (UC-1-119) — applied per-route on POST/PATCH/DELETE. A
 * route opts in as `idempotency-key:required` or `idempotency-key:optional`. On
 * a required route a missing `Idempotency-Key` header is rejected.
 *
 * Lookup / replay / conflict detection and the store-unavailable guard are
 * layered on in later UC-1-119 cycles.
 */
class EnsureIdempotencyKey
{
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($mode === 'required' && $key === null) {
            throw new IdempotencyKeyRequiredException;
        }

        return $next($request);
    }
}
