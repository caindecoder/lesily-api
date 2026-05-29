<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Context;

Route::get('correlation-probe', fn () => response()->json([
    'downstream_correlation_id' => Context::get('correlation_id'),
]));
