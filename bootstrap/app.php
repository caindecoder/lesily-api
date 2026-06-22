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
use App\Foundation\Http\Middleware\EnsureIdempotencyKey;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Companies\Exceptions\UnsupportedCountryException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use App\Foundation\Http\Middleware\EnsureFeatureAvailable;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use App\Foundation\Http\Exceptions\RoleInsufficientException;
use App\Foundation\Auth\Exceptions\InvalidCredentialsException;
use App\Foundation\Http\Exceptions\FeatureUnavailableException;
use App\Foundation\Http\Exceptions\CompanyAccessDeniedException;
use App\Foundation\Auth\Exceptions\InvalidTwoFactorCodeException;
use App\Foundation\Auth\Exceptions\RegistrationConflictException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use App\Foundation\Auth\Exceptions\LoginChallengeNotFoundException;
use App\Foundation\Http\Exceptions\IdempotencyKeyConflictException;
use App\Foundation\Http\Exceptions\IdempotencyKeyRequiredException;
use App\Foundation\Auth\Exceptions\TwoFactorAlreadyEnabledException;
use App\Foundation\Auth\Exceptions\TwoFactorSetupNotStartedException;
use App\Foundation\Http\Exceptions\IdempotencyKeyProcessingException;
use App\Foundation\Auth\Exceptions\TwoFactorVerificationFailedException;
use App\Foundation\Http\Exceptions\IdempotencyCacheUnavailableException;

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
            'idempotency-key' => EnsureIdempotencyKey::class,
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

        // A failed login (unknown email OR wrong password). Distinct from the
        // missing-token AuthenticationException above: same 401 code, but a
        // login-specific message — and byte-identical for both failure causes so
        // the response never reveals which was wrong (UC-1-002 anti-enumeration).
        $exceptions->render(fn (InvalidCredentialsException $e) => EnvelopeBuilder::build(
            ApiErrorCode::Unauthenticated,
            'Invalid credentials.',
        ));

        // A 2FA confirmation with no enrollment in progress (the staged secret expired
        // or setup was never started). UC-1-006: restart setup to get a fresh secret.
        $exceptions->render(fn (TwoFactorSetupNotStartedException $e) => EnvelopeBuilder::build(
            ApiErrorCode::TwoFactorSetupNotStarted,
            'Two-factor setup has not been started. Restart setup and try again.',
        ));

        // The submitted TOTP code did not verify against the staged secret (UC-1-006 4a).
        $exceptions->render(fn (InvalidTwoFactorCodeException $e) => EnvelopeBuilder::build(
            ApiErrorCode::InvalidTwoFactorCode,
            'That code is not valid. Open your authenticator app and enter the current code.',
        ));

        // Enrollment started on an account that already has 2FA enabled (UC-1-006 1b).
        $exceptions->render(fn (TwoFactorAlreadyEnabledException $e) => EnvelopeBuilder::build(
            ApiErrorCode::TwoFactorAlreadyEnabled,
            'Two-factor authentication is already enabled.',
        ));

        // The 2FA login challenge at /2fa/verify expired / never existed / was spent
        // (UC-1-005). 404 — the client restarts login to get a fresh challenge.
        $exceptions->render(fn (LoginChallengeNotFoundException $e) => EnvelopeBuilder::build(
            ApiErrorCode::TwoFactorChallengeNotFound,
            'This verification session has expired. Please log in again.',
        ));

        // The second factor at /2fa/verify matched neither the TOTP nor a recovery code
        // (UC-1-005 4a/3a). 401, one message — a single code field can't reveal which
        // factor was intended (the locked-out user is steered to support in the copy).
        $exceptions->render(fn (TwoFactorVerificationFailedException $e) => EnvelopeBuilder::build(
            ApiErrorCode::TwoFactorVerificationFailed,
            'Invalid verification code. Please try again, or contact support if you have lost your authenticator.',
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

        $exceptions->render(fn (IdempotencyKeyRequiredException $e) => EnvelopeBuilder::build(
            ApiErrorCode::IdempotencyKeyRequired,
            $e->getMessage(),
        ));

        $exceptions->render(fn (IdempotencyCacheUnavailableException $e) => EnvelopeBuilder::build(
            ApiErrorCode::IdempotencyCacheUnavailable,
            $e->getMessage(),
        ));

        $exceptions->render(fn (IdempotencyKeyConflictException $e) => EnvelopeBuilder::build(
            ApiErrorCode::IdempotencyKeyConflict,
            $e->getMessage(),
        ));

        // A concurrent duplicate lost the atomic reservation race while the first
        // request is still in flight (OQ-1-114). Mirrors the throttle handler:
        // retry_after in the body + a Retry-After header so the client retries
        // into the replay path once the winner completes.
        $exceptions->render(fn (IdempotencyKeyProcessingException $e) => EnvelopeBuilder::build(
            ApiErrorCode::IdempotencyKeyProcessing,
            $e->getMessage(),
            ['retry_after' => $e->retryAfter],
        )->withHeaders(['Retry-After' => (string) $e->retryAfter]));

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

        // Status-preserving fallback for any Symfony HttpException without a
        // dedicated handler (405, 410, 415, …). Registered AFTER the specific
        // HttpException handlers (404, 403 signed-link, 429) so those win, and
        // BEFORE the Throwable catch-all so unmapped HttpExceptions keep their
        // real status instead of collapsing to 500 when APP_DEBUG=false (OQ-1-112).
        $exceptions->render(fn (HttpExceptionInterface $e) => EnvelopeBuilder::buildWithStatus(
            $e->getStatusCode(),
            ApiErrorCode::HttpError,
            'The request could not be completed.',
        )->withHeaders($e->getHeaders()));

        $exceptions->render(function (Throwable $e) {
            if (config('app.debug')) {
                return null;
            }

            return EnvelopeBuilder::build(ApiErrorCode::InternalError, 'An unexpected error occurred.');
        });
    })->create();
