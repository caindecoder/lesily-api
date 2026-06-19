<?php

use Illuminate\Support\Facades\Route;
use App\Foundation\Http\Middleware\AssignCorrelationId;
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

// Email verification (UC-1-001 alt-6a) — the signed link IS the credential (no
// auth token); `hash` = sha1(user email) per api-1-auth-onboarding. No route is
// gated on verified status this slice (no enforcement).
Route::get('/v1/auth/email/verify/{id}/{hash}', EmailVerificationController::class)
    ->middleware(['signed', 'throttle:auth-tier'])
    ->name('verification.verify');
