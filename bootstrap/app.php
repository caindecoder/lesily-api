<?php

use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Illuminate\Validation\ValidationException;
use App\Foundation\Http\Responses\EnvelopeBuilder;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Foundation\Http\Middleware\AssignCorrelationId;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use App\Foundation\Http\Middleware\EnsureFeatureAvailable;
use App\Foundation\Http\Exceptions\RoleInsufficientException;
use App\Foundation\Http\Middleware\CheckPermissionMiddleware;
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
        $middleware->alias([
            'feature' => EnsureFeatureAvailable::class,
            'permission' => CheckPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->expectsJson()) {
                return EnvelopeBuilder::build(
                    $request,
                    status: 422,
                    errorCode: 'VALIDATION_FAILED',
                    message: $e->getMessage(),
                    extras: ['errors' => $e->errors()],
                );
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                return EnvelopeBuilder::build(
                    $request,
                    status: 404,
                    errorCode: 'RESOURCE_NOT_FOUND',
                    message: 'Resource not found.',
                );
            }
        });

        $exceptions->render(function (FeatureUnavailableException $e, Request $request) {
            if ($request->expectsJson()) {
                return EnvelopeBuilder::build(
                    $request,
                    status: 403,
                    errorCode: 'FEATURE_NOT_AVAILABLE',
                    message: $e->getMessage(),
                    extras: [
                        'upgrade_to' => $e->upgradeTo,
                        'upgrade_url' => $e->upgradeUrl,
                    ],
                );
            }
        });

        $exceptions->render(function (RoleInsufficientException $e, Request $request) {
            if ($request->expectsJson()) {
                return EnvelopeBuilder::build(
                    $request,
                    status: 403,
                    errorCode: 'ROLE_INSUFFICIENT',
                    message: $e->getMessage(),
                    extras: ['user_role' => $e->userRoleCode],
                );
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->expectsJson()) {
                // Laravel's ThrottleRequests middleware always sets Retry-After when it constructs
                // ThrottleRequestsException; the 60s fallback is defense-in-depth for direct constructions.
                $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);
                $resetAt = (string) (time() + $retryAfter);

                $response = EnvelopeBuilder::build(
                    $request,
                    status: 429,
                    errorCode: 'RATE_LIMIT_EXCEEDED',
                    message: 'Too many requests. Please try again later.',
                    extras: ['retry_after' => $retryAfter],
                );
                $response->headers->set('Retry-After', (string) $retryAfter);
                $response->headers->set('X-RateLimit-Reset', $resetAt);

                return $response;
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof ValidationException
                || $e instanceof NotFoundHttpException
                || $e instanceof FeatureUnavailableException
                || $e instanceof RoleInsufficientException
                || $e instanceof ThrottleRequestsException) {
                return null;
            }

            if ($request->expectsJson() && ! config('app.debug')) {
                return EnvelopeBuilder::build(
                    $request,
                    status: 500,
                    errorCode: 'INTERNAL_ERROR',
                    message: 'An unexpected error occurred.',
                );
            }

            return null;
        });
    })->create();
