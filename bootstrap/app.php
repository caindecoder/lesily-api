<?php

use Psr\Log\LogLevel;
use Illuminate\Http\Request;
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
use App\Companies\Exceptions\StaleOwnershipException;
use App\Companies\Exceptions\TargetNotMemberException;
use App\Foundation\Http\Middleware\AssignCorrelationId;
use App\Foundation\Http\Middleware\EnsurePlatformAdmin;
use App\Foundation\Http\Middleware\RecordAccessOutcome;
use App\Foundation\Http\Middleware\EnsureIdempotencyKey;
use App\Companies\Exceptions\UnsupportedCountryException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use App\Companies\Exceptions\SelfTransferBlockedException;
use App\Foundation\Http\Middleware\EnsureFeatureAvailable;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use App\Foundation\Auth\Exceptions\InvalidResetLinkException;
use App\Foundation\Http\Exceptions\RoleInsufficientException;
use App\Foundation\Auth\Exceptions\IncorrectPasswordException;
use App\Compliance\Dsar\Exceptions\DsarExportNotReadyException;
use App\Foundation\Auth\Exceptions\DeviceLimitReachedException;
use App\Foundation\Auth\Exceptions\InvalidCredentialsException;
use App\Foundation\Http\Exceptions\FeatureUnavailableException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use App\Companies\Memberships\Exceptions\AlreadyMemberException;
use App\Compliance\Dsar\Exceptions\DsarDownloadExpiredException;
use App\Foundation\Auth\Exceptions\TwoFactorNotEnabledException;
use App\Foundation\Http\Exceptions\CompanyAccessDeniedException;
use App\Billing\Invoices\Exceptions\ReceiptNotAvailableException;
use App\Foundation\Auth\Exceptions\InvalidTwoFactorCodeException;
use App\Foundation\Auth\Exceptions\RegistrationConflictException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Companies\Exceptions\OwnershipTransferContentionException;
use App\Compliance\Dsar\Exceptions\DsarRequesterMismatchException;
use App\Compliance\Dsar\Exceptions\DsarRequestInProgressException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use App\Companies\Exceptions\OwnershipTransferCaseNotOpenException;
use App\Foundation\Auth\Exceptions\LoginChallengeNotFoundException;
use App\Foundation\Http\Exceptions\IdempotencyKeyConflictException;
use App\Foundation\Http\Exceptions\IdempotencyKeyRequiredException;
use App\Billing\Invoices\Exceptions\ReceiptRenderingFailedException;
use App\Companies\Memberships\Exceptions\InvalidInvitationException;
use App\Foundation\Auth\Exceptions\TwoFactorAlreadyEnabledException;
use App\Companies\Memberships\Exceptions\SelfRemovalBlockedException;
use App\Compliance\Dsar\Exceptions\DsarRequestNotInProgressException;
use App\Foundation\Auth\Exceptions\TwoFactorSetupNotStartedException;
use App\Foundation\Http\Exceptions\IdempotencyKeyProcessingException;
use App\Companies\Memberships\Exceptions\InviteEmailMismatchException;
use App\Companies\Memberships\Exceptions\OwnerRemovalBlockedException;
use App\Foundation\AccessLog\Exceptions\AccessLogWriteFailedException;
use App\Companies\Exceptions\OwnershipTransferCaseNotVerifiedException;
use App\Companies\Memberships\Exceptions\InvitationNotPendingException;
use App\Companies\Memberships\Exceptions\MembershipContentionException;
use App\Companies\Memberships\Exceptions\OwnerTransferRequiredException;
use App\Companies\Memberships\Exceptions\SelfRoleChangeBlockedException;
use App\Foundation\Auth\Exceptions\TwoFactorVerificationFailedException;
use App\Foundation\Http\Exceptions\IdempotencyCacheUnavailableException;
use App\Companies\Exceptions\OwnershipTransferCaseAlreadyClosedException;
use App\Companies\Memberships\Exceptions\InvitationAcceptContentionException;

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
        // The host's edge proxy is the only way in; its X-Forwarded-For carries the client's address.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->appendToGroup('api', AssignCorrelationId::class);
        // Laravel sorts auth and throttle to the front; a refusal they answer still needs its id.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, AssignCorrelationId::class);
        $middleware->appendToGroup('api', RecordAccessOutcome::class);
        $middleware->alias([
            'auth.company' => AuthorizeCompany::class,
            'feature' => EnsureFeatureAvailable::class,
            'permission' => EnsurePermission::class,
            'idempotency-key' => EnsureIdempotencyKey::class,
            'platform.admin' => EnsurePlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->level(AccessLogWriteFailedException::class, LogLevel::CRITICAL);

        // Refusals the access log records; reporting them as errors only duplicated the row.
        $exceptions->dontReport([
            InvalidCredentialsException::class,
            TwoFactorVerificationFailedException::class,
            IncorrectPasswordException::class,
            DeviceLimitReachedException::class,
            CompanyAccessDeniedException::class,
            FeatureUnavailableException::class,
            RoleInsufficientException::class,
            InviteEmailMismatchException::class,
            DsarRequesterMismatchException::class,
            LoginChallengeNotFoundException::class,
        ]);

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

        // A disable / regenerate-recovery-codes request on an account with no 2FA enabled
        // (UC-1-007 1a). 422 — there is nothing to act on.
        $exceptions->render(fn (TwoFactorNotEnabledException $e) => EnvelopeBuilder::build(
            ApiErrorCode::TwoFactorNotEnabled,
            'Two-factor authentication is not enabled.',
        ));

        // A password re-auth for a sensitive 2FA change failed (UC-1-007 5a / UC-1-137).
        // 401 — a distinct INCORRECT_PASSWORD code (not the login UNAUTHENTICATED) so the
        // client surfaces "wrong password" inline instead of bouncing to login.
        $exceptions->render(fn (IncorrectPasswordException $e) => EnvelopeBuilder::build(
            ApiErrorCode::IncorrectPassword,
            'Incorrect password. Please try again.',
        ));

        // A reset submit whose token the broker rejected — never issued, expired (>60 min), or already
        // used. Collapsed to one 422 RESET_LINK_INVALID (a consumed token is deleted, so expired vs used
        // are indistinguishable, and collapsing avoids leaking token state) (UC-1-008 5a).
        $exceptions->render(fn (InvalidResetLinkException $e) => EnvelopeBuilder::build(
            ApiErrorCode::ResetLinkInvalid,
            'This reset link is invalid or has expired. Request a new one.',
        ));

        // An accept token that resolves to no live pending invitation — never issued,
        // expired, already used, or (Task 5) revoked. ONE collapsed code + message for
        // every cause so a probe cannot learn token state (UC-1-019, H4 session 109).
        $exceptions->render(fn (InvalidInvitationException $e) => EnvelopeBuilder::build(
            ApiErrorCode::InviteInvalid,
            $e->getMessage(),
        ));

        // A resend hit an invitation that is no longer pending — accepted, expired,
        // or (Task 5) revoked. ONE collapsed code + message; admin-facing counterpart
        // to the accept path's INVITE_INVALID (UC-1-021, R3 session 110).
        $exceptions->render(fn (InvitationNotPendingException $e) => EnvelopeBuilder::build(
            ApiErrorCode::InviteNotPending,
            $e->getMessage(),
        ));

        // A role change targeted the Owner's membership — only the ownership-transfer
        // flow may change the Owner's role (UC-1-023 R-A s112; §S10b one-owner).
        $exceptions->render(fn (OwnerTransferRequiredException $e) => EnvelopeBuilder::build(
            ApiErrorCode::OwnerTransferRequired,
            $e->getMessage(),
        ));

        // An actor tried to change their own role (UC-1-023 flow 4d, R-B s112) —
        // another Owner/Admin must make the change.
        $exceptions->render(fn (SelfRoleChangeBlockedException $e) => EnvelopeBuilder::build(
            ApiErrorCode::SelfRoleChangeBlocked,
            $e->getMessage(),
        ));

        // An actor tried to remove their own membership (UC-1-024 flow 2b, R-B s113)
        // — another Owner/Admin must remove them.
        $exceptions->render(fn (SelfRemovalBlockedException $e) => EnvelopeBuilder::build(
            ApiErrorCode::SelfRemovalBlocked,
            $e->getMessage(),
        ));

        // An actor tried to remove the company Owner (UC-1-024 flow 2a, R-A s113) —
        // the immutable Owner leaves only via ownership transfer; reuses the
        // OWNER_TRANSFER_REQUIRED (422) code with a removal-specific message.
        $exceptions->render(fn (OwnerRemovalBlockedException $e) => EnvelopeBuilder::build(
            ApiErrorCode::OwnerTransferRequired,
            $e->getMessage(),
        ));

        // An ownership transfer named a target with no live membership here —
        // an unaccepted invitation is not membership (UC-1-028 flow 3a).
        $exceptions->render(fn (TargetNotMemberException $e) => EnvelopeBuilder::build(
            ApiErrorCode::TargetNotMember,
            $e->getMessage(),
        ));

        // The Owner named themselves as the transfer target (UC-1-028 flow 1c).
        $exceptions->render(fn (SelfTransferBlockedException $e) => EnvelopeBuilder::build(
            ApiErrorCode::SelfTransferBlocked,
            $e->getMessage(),
        ));

        // Under the row lock the company's owner is no longer the actor — a
        // concurrent transfer won (UC-1-028 flow 3b; the world moved → 409).
        $exceptions->render(fn (StaleOwnershipException $e) => EnvelopeBuilder::build(
            ApiErrorCode::StateGuardViolation,
            $e->getMessage(),
        ));

        // An open DSAR already exists for the subject in this company (UC-1-070 2a).
        $exceptions->render(fn (DsarRequestInProgressException $e) => EnvelopeBuilder::build(
            ApiErrorCode::DsarInProgress,
            $e->getMessage(),
        ));

        // Only the original requester may withdraw a DSAR request (UC-1-102 4b, V5).
        $exceptions->render(fn (DsarRequesterMismatchException $e) => EnvelopeBuilder::build(
            ApiErrorCode::DsarRequesterMismatch,
            $e->getMessage(),
        ));

        // A DSAR export can be downloaded only once it is fulfilled (UC-1-070 5a).
        $exceptions->render(fn (DsarExportNotReadyException $e) => EnvelopeBuilder::build(
            ApiErrorCode::DsarNotReady,
            $e->getMessage(),
        ));

        // A DSAR export can be downloaded for seven days from fulfilment (UC-1-070 5b, D3).
        $exceptions->render(fn (DsarDownloadExpiredException $e) => EnvelopeBuilder::build(
            ApiErrorCode::DsarDownloadExpired,
            $e->getMessage(),
        ));

        // A DSAR request that is no longer in progress cannot be withdrawn (UC-1-102 4a, D7).
        $exceptions->render(fn (DsarRequestNotInProgressException $e) => EnvelopeBuilder::build(
            ApiErrorCode::StateGuardViolation,
            $e->getMessage(),
        ));

        // Only an open ownership-transfer case may be verified — the case had
        // already moved on (UC-1-113 flow 4a; the world moved → 409).
        $exceptions->render(fn (OwnershipTransferCaseNotOpenException $e) => EnvelopeBuilder::build(
            ApiErrorCode::StateGuardViolation,
            $e->getMessage(),
        ));

        // Only a verified ownership-transfer case may be executed — open or
        // already closed refuses with the state named (UC-1-093 flow 3a → 409).
        $exceptions->render(fn (OwnershipTransferCaseNotVerifiedException $e) => EnvelopeBuilder::build(
            ApiErrorCode::StateGuardViolation,
            $e->getMessage(),
        ));

        // A rejection may close an open OR a verified case — one already
        // closed refuses with the state named (UC-1-128 flow 4a → 409).
        $exceptions->render(fn (OwnershipTransferCaseAlreadyClosedException $e) => EnvelopeBuilder::build(
            ApiErrorCode::StateGuardViolation,
            $e->getMessage(),
        ));

        // The transfer's locked transaction lost to lock contention (MySQL
        // 1205/1213 — ruled s170: same 409 family as the stale-owner guard).
        $exceptions->render(fn (OwnershipTransferContentionException $e) => EnvelopeBuilder::build(
            ApiErrorCode::StateGuardViolation,
            $e->getMessage(),
        ));

        // The invitation accept's locked transaction lost to lock contention
        // (OQ-1-164 — the same 409 family; the link stays valid, retry wins).
        $exceptions->render(fn (InvitationAcceptContentionException $e) => EnvelopeBuilder::build(
            ApiErrorCode::StateGuardViolation,
            $e->getMessage(),
        ));

        // A membership write (role change / removal) lost to lock contention
        // on the pivot rows the accept path also locks (OQ-1-164 F3).
        $exceptions->render(fn (MembershipContentionException $e) => EnvelopeBuilder::build(
            ApiErrorCode::StateGuardViolation,
            $e->getMessage(),
        ));

        // An authenticated user tried to consume an invitation addressed to a
        // different email (UC-1-019 alt-4b) — only the invited account may accept.
        $exceptions->render(fn (InviteEmailMismatchException $e) => EnvelopeBuilder::build(
            ApiErrorCode::InviteEmailMismatch,
            $e->getMessage(),
        ));

        // A Pro/Enterprise account at its concurrent-device limit tried to open a 4th
        // session at login / 2FA-verify (UC-1-002 alt-5a). 403 — the cap is enforced
        // before the mint, so the existing sessions are untouched.
        $exceptions->render(fn (DeviceLimitReachedException $e) => EnvelopeBuilder::build(
            ApiErrorCode::DeviceLimitReached,
            'Maximum device limit reached. Log out from another device first.',
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

        // An invite targeted an email that is already an active member (UC-1-018 3e).
        $exceptions->render(fn (AlreadyMemberException $e) => EnvelopeBuilder::build(
            ApiErrorCode::AlreadyMember,
            $e->getMessage(),
        ));

        // The receipt state guard refused the download — non-paid status (echoed
        // as current_status) or an open dispute (UC-1-121 5a/5b; s135 F-c).
        $exceptions->render(fn (ReceiptNotAvailableException $e) => EnvelopeBuilder::build(
            ApiErrorCode::StateGuardViolation,
            $e->getMessage(),
            $e->currentStatus === null ? [] : ['current_status' => $e->currentStatus],
        ));

        // The PDF renderer failed mid-request — a JSON 503 with a retry hint,
        // NEVER a truncated application/pdf stream (UC-1-121 7b; s135 F-d). The
        // internal cause stays out of the response; the generic message is the
        // client contract.
        $exceptions->render(fn (ReceiptRenderingFailedException $e) => EnvelopeBuilder::build(
            ApiErrorCode::ReceiptGenerationUnavailable,
            'Receipt generation is temporarily unavailable. Please try again shortly.',
        )->withHeaders(['Retry-After' => '30']));

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
