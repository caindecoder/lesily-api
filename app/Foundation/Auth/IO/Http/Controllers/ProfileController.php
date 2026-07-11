<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class ProfileController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json();
    }
}
