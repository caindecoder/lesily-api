<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Foundation\Http\Exceptions\FeatureUnavailableException;

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

Route::get('error-internal', fn () => throw new RuntimeException('boom'));
