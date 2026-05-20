<?php

declare(strict_types=1);

namespace App\Foundation\Health\IO\Http\Controllers;

use App\Foundation\Health\Services\HealthChecker;
use Illuminate\Http\JsonResponse;

final class HealthCheckController
{
    public function __invoke(HealthChecker $checker): JsonResponse
    {
        $isHealthy = $checker->isHealthy();

        return response()->json([
            'status' => $isHealthy ? 'healthy' : 'unhealthy',
            'checked_at' => now()->toIso8601String(),
            'subsystems' => $checker->subsystems(),
        ], $isHealthy ? 200 : 503);
    }
}
