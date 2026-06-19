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
// per-IP limit (10/min, defined in AppServiceProvider). Idempotency-Key is
// optional (OQ-1-114, C-i): a key-carrying double-submit replays the created
// account; keyless clients fall through to the DB-uniqueness 409 backstop.
Route::post('/v1/auth/register', RegisterController::class)
    ->middleware(['throttle:auth-tier', 'idempotency-key:optional']);

// Email verification (UC-1-001 alt-6a) — the signed link IS the credential (no
// auth token); `hash` = sha1(user email) per api-1-auth-onboarding. No route is
// gated on verified status this slice (no enforcement).
Route::get('/v1/auth/email/verify/{id}/{hash}', EmailVerificationController::class)
    ->middleware(['signed', 'throttle:auth-tier'])
    ->name('verification.verify');
