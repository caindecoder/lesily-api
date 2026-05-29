<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Context;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Foundation\Http\Exceptions\FeatureUnavailableException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

Route::get('correlation-probe', fn () => response()->json([
    'downstream_correlation_id' => Context::get('correlation_id'),
]));

Route::get('correlation-error-probe', fn () => response()->json([
    'status' => 'error',
], 404));

Route::get('error-validation', fn () => throw ValidationException::withMessages([
    'name' => ['The name field is required.'],
]));

Route::get('error-feature-gate', fn () => throw new FeatureUnavailableException('Pro', '/settings/billing/upgrade'));

Route::get('error-not-found', fn () => throw new ModelNotFoundException);

Route::get('error-http-not-found', fn () => throw new NotFoundHttpException);

Route::get('error-internal', fn () => throw new RuntimeException('boom'));

// Rate-limit probes (UC-1-098). Real Laravel throttle middleware drives the
// 429 so the ThrottleRequestsException render handler is exercised end-to-end.
RateLimiter::for('rate-limit-general', fn (Request $request) => Limit::perMinute(1)->by((string) $request->ip()));

Route::get('rate-limit-general-probe', fn () => response()->json(['ok' => true]))
    ->middleware('throttle:rate-limit-general');

RateLimiter::for('rate-limit-auth', fn (Request $request) => Limit::perMinute(1)->by((string) $request->ip()));

Route::post('rate-limit-auth-probe', fn () => response()->json(['ok' => true]))
    ->middleware('throttle:rate-limit-auth');
