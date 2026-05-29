<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use Illuminate\Validation\ValidationException;
use App\Foundation\Http\Responses\EnvelopeBuilder;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Foundation\Http\Middleware\AssignCorrelationId;
use App\Foundation\Http\Exceptions\FeatureUnavailableException;

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
            'VALIDATION_FAILED',
            $e->getMessage(),
            422,
            $e->errors(),
        ));

        $exceptions->render(fn (FeatureUnavailableException $e) => EnvelopeBuilder::build(
            'FEATURE_NOT_AVAILABLE',
            $e->getMessage(),
            403,
            extra: ['upgrade_to' => $e->upgradeTo, 'upgrade_url' => $e->upgradeUrl],
        ));

        $exceptions->render(function (Throwable $e) {
            if (config('app.debug')) {
                return null;
            }

            return EnvelopeBuilder::build('INTERNAL_ERROR', 'An unexpected error occurred.', 500);
        });
    })->create();
