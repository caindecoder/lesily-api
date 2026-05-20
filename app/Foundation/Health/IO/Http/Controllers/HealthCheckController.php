<?php

declare(strict_types=1);

namespace App\Foundation\Health\IO\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Foundation\Health\Services\HealthChecker;

final class HealthCheckController
{
    public function __invoke(HealthChecker $checker): JsonResponse
    {
        $isHealthy = $checker->isHealthy();

        return response()->json([
            'status' => $isHealthy ? 'healthy' : 'unhealthy',
            'checked_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'subsystems' => $checker->subsystems(),
        ], $isHealthy ? 200 : 503);
    }
}
