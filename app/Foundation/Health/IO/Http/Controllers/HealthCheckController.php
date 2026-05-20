<?php

declare(strict_types=1);

namespace App\Foundation\Health\IO\Http\Controllers;

use App\Foundation\Health\Services\HealthChecker;
use Illuminate\Http\JsonResponse;

final class HealthCheckController
{
    public function __invoke(HealthChecker $checker): JsonResponse
    {
        return response()->json([
            'status' => 'unhealthy',
            'subsystems' => $checker->subsystems(),
        ], 503);
    }
}
