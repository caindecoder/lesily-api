<?php

namespace App\Compliance\Breach\IO\Http\Controllers;

use RuntimeException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class PlatformAdminBreachIncidentsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        throw new RuntimeException('Not implemented');
    }
}
