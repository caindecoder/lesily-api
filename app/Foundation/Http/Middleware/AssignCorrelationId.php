<?php

namespace App\Foundation\Http\Middleware;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('Correlation-Id') ?: (string) Str::uuid7();
        $request->attributes->set('correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set('Correlation-Id', $correlationId);

        return $response;
    }
}
