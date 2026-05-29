<?php

use Illuminate\Support\Facades\Route;
use App\Foundation\Health\IO\Http\Controllers\HealthCheckController;

Route::get('/health', HealthCheckController::class);
