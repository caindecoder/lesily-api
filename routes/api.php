<?php

use Illuminate\Support\Facades\Route;
use App\Foundation\Http\Middleware\AssignCorrelationId;
use App\Foundation\Auth\IO\Http\Controllers\LoginController;
use App\Foundation\Auth\IO\Http\Controllers\LogoutController;
use App\Foundation\Auth\IO\Http\Controllers\RegisterController;
use App\Foundation\Health\IO\Http\Controllers\HealthCheckController;
use App\Foundation\Auth\IO\Http\Controllers\EnableTwoFactorController;
use App\Foundation\Auth\IO\Http\Controllers\VerifyTwoFactorController;
use App\Foundation\Auth\IO\Http\Controllers\ConfirmTwoFactorController;
use App\Foundation\Auth\IO\Http\Controllers\DisableTwoFactorController;
use App\Foundation\Auth\IO\Http\Controllers\EmailVerificationController;

// Health is operational tooling and opts out of the platform cross-cutting
// middleware (no Correlation-Id) — UC-1-118 / cd-foundation-http §4.2.
Route::get('/health', HealthCheckController::class)
    ->withoutMiddleware(AssignCorrelationId::class);

// Owner signup — public per api-1-auth-onboarding §register, under the auth-tier
// per-IP limit (10/min, defined in AppServiceProvider). Idempotency is
// deliberately NOT mounted here (OQ-1-114 C-iii): the 2xx body carries a
// plaintext API token, which the idempotency response cache would hold at rest
// for 24h. Concurrent duplicates are data-safe via the DB-uniqueness 409
// backstop (OQ-1-111); mount idempotency here only once token caching is resolved.
Route::post('/v1/auth/register', RegisterController::class)
    ->middleware('throttle:auth-tier');

// Email+password login (UC-1-002) — public per api-1-auth-onboarding §login, under
// the same auth-tier per-IP limit (10/min) as register. Token-only (cd-auth token-only
// banner): the credential check issues a Sanctum token, no domain event.
Route::post('/v1/auth/login', LoginController::class)
    ->middleware('throttle:auth-tier');

// Log out (UC-1-004) — revoke the CURRENT Sanctum token only (not other devices;
// logout-all is UC-1-011, deferred). Token-only (cd-auth §5): a thin controller
// deletes the access token, no domain event. Bare auth:sanctum, no throttle — the
// slice's authoritative contract (the api-doc's tenant-tier row predates this and
// has no limiter to bind to; reconciled at slice close). LeaveDates parity: its
// auth-gated token-destroy is likewise un-throttled.
Route::post('/v1/auth/logout', LogoutController::class)
    ->middleware('auth:sanctum');

// Email verification (UC-1-001 alt-6a) — the signed link IS the credential (no
// auth token); `hash` = sha1(user email) per api-1-auth-onboarding. No route is
// gated on verified status this slice (no enforcement).
Route::get('/v1/auth/email/verify/{id}/{hash}', EmailVerificationController::class)
    ->middleware(['signed', 'throttle:auth-tier'])
    ->name('verification.verify');

// Set up 2FA — enable step (UC-1-006), Pro+ via the feature gate. Generates a TOTP
// secret + otpauth:// URI and stages it in a short-TTL cache; nothing persists to the
// users row until /2fa/confirm verifies a code. auth:sanctum -> auth.company ->
// feature:two_factor_auth (no permission gate — self-service, all roles per UC-1-006).
Route::post('/v1/auth/2fa/enable', EnableTwoFactorController::class)
    ->middleware(['auth:sanctum', 'auth.company', 'feature:two_factor_auth']);

// Confirm 2FA — verify the TOTP code against the staged secret (UC-1-006 step 5),
// then enable 2FA and return the one-time recovery codes. Same gate as enable, plus
// throttle:auth-tier (brute-force surface — the code is being checked here).
Route::post('/v1/auth/2fa/confirm', ConfirmTwoFactorController::class)
    ->middleware(['auth:sanctum', 'auth.company', 'feature:two_factor_auth', 'throttle:auth-tier']);

// Verify 2FA at login (UC-1-005) — the second factor. Public: the opaque challenge_id
// (issued by the login 202, alt-4a) is the auth proof, consumed here. Token-only — mints
// the Sanctum token, records no UserLoggedIn (cd-auth token-only). throttle:auth-tier
// guards the per-IP brute-force surface; the per-challenge 5-attempt cap lives in the store.
Route::post('/v1/auth/2fa/verify', VerifyTwoFactorController::class)
    ->middleware('throttle:auth-tier');

// Disable 2FA (UC-1-007) — password re-auth. throttle:auth-tier AFTER auth:sanctum: the
// interactor runs Hash::check, so this is a brute-force surface like /2fa/confirm and
// /2fa/verify (a stolen token must not become an unthrottled password-guessing oracle) —
// NOT a credential-free action like logout/enable (slice R9 reconciled). On success it
// wipes the secret + recovery codes and records a non-secret User2FADisabled marker → 204.
Route::delete('/v1/auth/2fa', DisableTwoFactorController::class)
    ->middleware(['auth:sanctum', 'throttle:auth-tier']);
