<?php

namespace App\Foundation\Health\IO\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'unhealthy',
            'checked_at' => '2026-05-29T00:00:00Z',
            'subsystems' => [
                'app' => 'up',
                'database' => 'down',
                'redis' => 'up',
                'queue' => 'up',
            ],
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
