<?php

namespace App\Foundation\Health\IO\Http\Controllers;

use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\Health\Enums\HealthStatus;
use App\Foundation\Health\Actions\CheckHealthAction;

class HealthCheckController extends Controller
{
    #[OA\Get(path: '/api/v1/health')]
    public function __invoke(CheckHealthAction $action): JsonResponse
    {
        $result = $action->execute();

        return response()->json(
            $result,
            $this->httpStatusCodeFor($result['status'])
        );
    }

    protected function httpStatusCodeFor(HealthStatus $status): int
    {
        return match ($status) {
            HealthStatus::Healthy => Response::HTTP_OK,
            HealthStatus::Unhealthy => Response::HTTP_SERVICE_UNAVAILABLE,
        };
    }
}
