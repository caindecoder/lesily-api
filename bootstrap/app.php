<?php

use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Foundation\Http\Middleware\AssignCorrelationId;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use App\Foundation\Http\Exceptions\FeatureUnavailableException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignCorrelationId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'error_code' => 'VALIDATION_FAILED',
                    'errors' => $e->errors(),
                    'correlation_id' => $request->attributes->get('correlation_id', '00000000-0000-0000-0000-000000000000'),
                ], 422);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Resource not found.',
                    'error_code' => 'RESOURCE_NOT_FOUND',
                    'errors' => (object) [],
                    'correlation_id' => $request->attributes->get('correlation_id', '00000000-0000-0000-0000-000000000000'),
                ], 404);
            }
        });

        $exceptions->render(function (FeatureUnavailableException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'error_code' => 'FEATURE_NOT_AVAILABLE',
                    'errors' => (object) [],
                    'upgrade_to' => $e->upgradeTo,
                    'upgrade_url' => $e->upgradeUrl,
                    'correlation_id' => $request->attributes->get('correlation_id', '00000000-0000-0000-0000-000000000000'),
                ], 403);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->expectsJson()) {
                // Laravel's ThrottleRequests middleware always sets Retry-After when it constructs
                // ThrottleRequestsException; the 60s fallback is defense-in-depth for direct constructions.
                $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);
                $resetAt = (string) (time() + $retryAfter);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Too many requests. Please try again later.',
                    'error_code' => 'RATE_LIMIT_EXCEEDED',
                    'errors' => (object) [],
                    'retry_after' => $retryAfter,
                    'correlation_id' => $request->attributes->get('correlation_id', '00000000-0000-0000-0000-000000000000'),
                ], 429, [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Reset' => $resetAt,
                ]);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof ValidationException
                || $e instanceof NotFoundHttpException
                || $e instanceof FeatureUnavailableException
                || $e instanceof ThrottleRequestsException) {
                return null;
            }

            if ($request->expectsJson() && ! config('app.debug')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'An unexpected error occurred.',
                    'error_code' => 'INTERNAL_ERROR',
                    'errors' => (object) [],
                    'correlation_id' => $request->attributes->get('correlation_id', '00000000-0000-0000-0000-000000000000'),
                ], 500);
            }

            return null;
        });
    })->create();
