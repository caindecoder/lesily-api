<?php

namespace App\Companies\Memberships\IO\Http\Controllers;

use Illuminate\Http\JsonResponse;

class RolesController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}
