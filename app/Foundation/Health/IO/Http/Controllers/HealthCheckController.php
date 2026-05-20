<?php

declare(strict_types=1);

namespace App\Foundation\Health\IO\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class HealthCheckController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'unhealthy',
            'subsystems' => ['database' => 'down'],
        ], 503);
    }
}
