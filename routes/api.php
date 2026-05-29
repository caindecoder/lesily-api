<?php

use Illuminate\Support\Facades\Route;
use App\Foundation\Http\Middleware\AssignCorrelationId;
use App\Foundation\Health\IO\Http\Controllers\HealthCheckController;

// Health is operational tooling and opts out of the platform cross-cutting
// middleware (no Correlation-Id) — UC-1-118 / cd-foundation-http §4.2.
Route::get('/health', HealthCheckController::class)
    ->withoutMiddleware(AssignCorrelationId::class);
