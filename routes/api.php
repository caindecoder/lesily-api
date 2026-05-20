<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Foundation\Health\IO\Http\Controllers\HealthCheckController;

// UC-1-097 — Health Check Endpoint. Registered OUTSIDE the auth/correlation/rate-limit
// middleware stack per api-1-infrastructure.md §3.1.2 (operational endpoint, must remain
// accessible during incidents). Path is unversioned: /api/health (not /api/v1/health).
Route::get('/health', HealthCheckController::class);
