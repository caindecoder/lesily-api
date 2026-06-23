<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class RegenerateRecoveryCodesController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([]);
    }
}
