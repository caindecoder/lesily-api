<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\Http\Responses\EnvelopeBuilder;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Foundation\Http\Middleware\AuthorizeCompany;
use App\Foundation\Http\Middleware\EnsurePermission;
use App\Foundation\Http\Middleware\AssignCorrelationId;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Companies\Exceptions\UnsupportedCountryException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use App\Foundation\Http\Middleware\EnsureFeatureAvailable;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use App\Foundation\Http\Exceptions\RoleInsufficientException;
use App\Foundation\Http\Exceptions\FeatureUnavailableException;
use App\Foundation\Http\Exceptions\CompanyAccessDeniedException;
use App\Foundation\Auth\Exceptions\RegistrationConflictException;
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
        $middleware->alias([
            'auth.company' => AuthorizeCompany::class,
            'feature' => EnsureFeatureAvailable::class,
            'permission' => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (ValidationException $e) => EnvelopeBuilder::build(
            ApiErrorCode::ValidationFailed,
            $e->getMessage(),
            ['errors' => $e->errors()],
        ));

        $exceptions->render(fn (AuthenticationException $e) => EnvelopeBuilder::build(
            ApiErrorCode::Unauthenticated,
            'Unauthenticated.',
        ));

        $exceptions->render(fn (CompanyAccessDeniedException $e) => EnvelopeBuilder::build(
            ApiErrorCode::CompanyAccessDenied,
            $e->getMessage(),
        ));

        $exceptions->render(fn (RegistrationConflictException $e) => EnvelopeBuilder::build(
            $e->errorCode,
            $e->getMessage(),
        ));

        $exceptions->render(fn (UnsupportedCountryException $e) => EnvelopeBuilder::build(
            ApiErrorCode::UnsupportedCountry,
            $e->getMessage(),
        ));

        $exceptions->render(fn (FeatureUnavailableException $e) => EnvelopeBuilder::build(
            ApiErrorCode::FeatureNotAvailable,
            $e->getMessage(),
            $e->responseExtras(),
        ));

        $exceptions->render(fn (RoleInsufficientException $e) => EnvelopeBuilder::build(
            ApiErrorCode::RoleInsufficient,
            $e->getMessage(),
            ['required_permission' => $e->permission],
        ));

        $exceptions->render(fn (ModelNotFoundException $e) => EnvelopeBuilder::build(
            ApiErrorCode::ResourceNotFound,
            'The requested resource was not found.',
        ));

        $exceptions->render(fn (NotFoundHttpException $e) => EnvelopeBuilder::build(
            ApiErrorCode::ResourceNotFound,
            'The requested resource was not found.',
        ));

        // A tampered or expired signed URL (e.g. the email-verification link). Mapped
        // explicitly to the §S21 403 envelope — otherwise it falls through to the
        // Throwable catch-all below, which renders 500 whenever APP_DEBUG is off (prod/CI).
        $exceptions->render(fn (InvalidSignatureException $e) => EnvelopeBuilder::build(
            ApiErrorCode::InvalidSignature,
            'The link is invalid or has expired.',
        ));

        $exceptions->render(function (ThrottleRequestsException $e) {
            // Laravel's ThrottleRequests middleware is the only source of this exception and
            // always sets Retry-After + X-RateLimit-Reset via buildException(), so the bare
            // header access is safe and deliberately has no fallback.
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
