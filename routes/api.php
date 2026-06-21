<?php

use Illuminate\Support\Facades\Route;
use App\Foundation\Http\Middleware\AssignCorrelationId;
use App\Foundation\Auth\IO\Http\Controllers\LoginController;
use App\Foundation\Auth\IO\Http\Controllers\LogoutController;
use App\Foundation\Auth\IO\Http\Controllers\RegisterController;
use App\Foundation\Health\IO\Http\Controllers\HealthCheckController;
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
