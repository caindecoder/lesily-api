<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use Illuminate\Validation\ValidationException;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\Http\Responses\EnvelopeBuilder;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Foundation\Http\Middleware\AssignCorrelationId;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use App\Foundation\Http\Exceptions\FeatureUnavailableException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            if (app()->environment('local', 'testing', 'e2e')) {
                Route::middleware('api')
                    ->prefix('api/_test')
                    ->group(base_path('routes/testing.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', AssignCorrelationId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (ValidationException $e) => EnvelopeBuilder::build(
            ApiErrorCode::ValidationFailed,
            $e->getMessage(),
            ['errors' => $e->errors()],
        ));

        $exceptions->render(fn (FeatureUnavailableException $e) => EnvelopeBuilder::build(
            ApiErrorCode::FeatureNotAvailable,
            $e->getMessage(),
            ['upgrade_to' => $e->upgradeTo, 'upgrade_url' => $e->upgradeUrl],
        ));

        $exceptions->render(fn (ModelNotFoundException $e) => EnvelopeBuilder::build(
            ApiErrorCode::ResourceNotFound,
            'The requested resource was not found.',
        ));

        $exceptions->render(fn (NotFoundHttpException $e) => EnvelopeBuilder::build(
            ApiErrorCode::ResourceNotFound,
            'The requested resource was not found.',
        ));

        $exceptions->render(function (ThrottleRequestsException $e) {
            $retryAfter = (int) $e->getHeaders()['Retry-After'];

            return EnvelopeBuilder::build(
                ApiErrorCode::RateLimitExceeded,
                'Too many requests. Please try again later.',
                ['retry_after' => $retryAfter],
            )->withHeaders([
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Reset' => (string) $e->getHeaders()['X-RateLimit-Reset'],
            ]);
        });

        $exceptions->render(function (Throwable $e) {
            if (config('app.debug')) {
                return null;
            }

            return EnvelopeBuilder::build(ApiErrorCode::InternalError, 'An unexpected error occurred.');
        });
    })->create();
