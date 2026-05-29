<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;

Route::get('correlation-probe', fn () => response()->json([
    'downstream_correlation_id' => Context::get('correlation_id'),
]));

Route::get('correlation-error-probe', fn () => response()->json([
    'status' => 'error',
], 404));

Route::get('error-validation', fn () => throw ValidationException::withMessages([
    'name' => ['The name field is required.'],
]));
