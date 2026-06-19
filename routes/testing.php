<?php

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Context;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Foundation\Http\Exceptions\FeatureUnavailableException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

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

RateLimiter::for('rate-limit-tenant', fn (Request $request) => Limit::perMinute(2)->by('tenant:'.$request->header('X-Tenant-Id', 'anonymous')));

Route::get('rate-limit-tenant-probe', fn () => response()->json(['ok' => true]))
    ->middleware('throttle:rate-limit-tenant');

// Two-gate probes (UC-1-055 + OQ-1-101). The production middleware stack per
// api-conventions §7 — auth:sanctum then the gates — drives the probes end-to-end;
// the acting user + X-Company-Context header are supplied by tests via
// WithRolePermissionFixtures.
Route::get('two-gate/starter-feature', fn () => response()->json(['ok' => true]))
    ->middleware(['auth:sanctum', 'auth.company', 'feature:employee_directory']);

Route::get('two-gate/pro-feature', fn () => response()->json(['ok' => true]))
    ->middleware(['auth:sanctum', 'auth.company', 'feature:two_factor_auth']);

Route::get('two-gate/secured', fn () => response()->json(['ok' => true]))
    ->middleware(['auth:sanctum', 'auth.company', 'feature:two_factor_auth', 'permission:manage_security']);

Route::get('two-gate/deprecated', fn () => response()->json(['ok' => true]))
    ->middleware(['auth:sanctum', 'auth.company', 'feature:legacy_import']);

Route::get('two-gate/templates', fn () => response()->json(['ok' => true]))
    ->middleware(['auth:sanctum', 'auth.company', 'feature:template_engine']);

// Company-context probe (OQ-1-101). Proves AuthorizeCompany binds the resolved
// company to the request for downstream consumers via $request->company().
Route::get('company-context-probe', fn (Request $request) => response()->json([
    'company_id' => $request->company()->getKey(),
]))->middleware(['auth:sanctum', 'auth.company']);

// Idempotency probes (UC-1-119). The production middleware (auth:sanctum then
// idempotency-key) drives the probe. The side-effect is a dispatched event so
// tests can assert it ran exactly once; the uuid body proves a replay is
// byte-identical (a wrongful re-execution would mint a new uuid). Tests
// authenticate via Sanctum::actingAs().
Route::post('idempotent-required', function () {
    event('probe.idempotent.executed');

    return response()->json(['id' => (string) Str::uuid()], 201);
})->middleware(['auth:sanctum', 'idempotency-key:required']);

Route::patch('idempotent-optional', function () {
    event('probe.idempotent.executed');

    return response()->json(['id' => (string) Str::uuid()]);
})->middleware(['auth:sanctum', 'idempotency-key:optional']);

// Failing-mutation probe (OQ-1-114). Returns a non-2xx after firing the
// executed event so tests can prove the reservation is released on failure: a
// retry with the same key re-executes (fires the event again) instead of being
// blocked as already-processing.
Route::post('idempotent-failing', function () {
    event('probe.idempotent.executed');

    return response()->json(['error' => 'rejected'], 422);
})->middleware(['auth:sanctum', 'idempotency-key:required']);

// Throwing-mutation probe (OQ-1-114). Throws after firing the executed event so
// tests can prove the reservation is released when the attempt errors out (the
// common register failure mode is a thrown exception, not a non-2xx response):
// a retry with the same key re-executes instead of being blocked.
Route::post('idempotent-throwing', function () {
    event('probe.idempotent.executed');

    throw new RuntimeException('boom');
})->middleware(['auth:sanctum', 'idempotency-key:required']);

// Unmapped-HttpException probes (OQ-1-112). Any Symfony HttpException without a
// dedicated render handler must preserve its status code (not collapse to 500
// when APP_DEBUG=false) AND its status-mandated headers via the
// HttpExceptionInterface fallback in bootstrap. error-method-not-allowed uses
// the real routing exception (carries an Allow header); error-http-detail
// proves the internal message is never leaked.
Route::get('error-method-not-allowed', fn () => throw new MethodNotAllowedHttpException(['POST']));

Route::get('error-gone', fn () => throw new HttpException(410));

Route::get('error-http-detail', fn () => throw new HttpException(410, 'sensitive internal detail'));
