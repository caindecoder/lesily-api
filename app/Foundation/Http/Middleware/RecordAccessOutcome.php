<?php

namespace App\Foundation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Foundation\AccessLog\UseCases\AccessLogRecorder;

class RecordAccessOutcome
{
    public function __construct(private readonly AccessLogRecorder $accessLogRecorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->accessLogRecorder->write($request, $response);
    }
}
