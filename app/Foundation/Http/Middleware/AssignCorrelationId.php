<?php

namespace App\Foundation\Http\Middleware;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('Correlation-Id') ?? Str::uuid7()->toString();

        Context::add('correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set('Correlation-Id', $correlationId);

        return $response;
    }
}
