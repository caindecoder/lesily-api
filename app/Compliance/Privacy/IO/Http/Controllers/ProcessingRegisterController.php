<?php

namespace App\Compliance\Privacy\IO\Http\Controllers;

use Illuminate\Http\JsonResponse;

class ProcessingRegisterController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}
