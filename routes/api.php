<?php

use Illuminate\Support\Facades\Route;
use App\Foundation\Http\Middleware\AssignCorrelationId;
use App\Foundation\Auth\IO\Http\Controllers\RegisterController;
use App\Foundation\Health\IO\Http\Controllers\HealthCheckController;

// Health is operational tooling and opts out of the platform cross-cutting
// middleware (no Correlation-Id) — UC-1-118 / cd-foundation-http §4.2.
Route::get('/health', HealthCheckController::class)
    ->withoutMiddleware(AssignCorrelationId::class);

// Owner signup — public per api-1-auth-onboarding §register, under the auth-tier
// per-IP limit (10/min, defined in AppServiceProvider). Idempotency-Key
// (UC-1-119) attaches when its middleware lands.
Route::post('/v1/auth/register', RegisterController::class)
    ->middleware('throttle:auth-tier');
