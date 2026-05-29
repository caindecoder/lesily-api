<?php

namespace App\Foundation\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

class EnvelopeBuilder
{
    public static function build(string $errorCode, string $message, int $status, array $errors = [], array $extra = []): JsonResponse
    {
        $correlationId = Context::get('correlation_id');

        return response()->json([
            'status' => 'error',
            'message' => $message,
            'error_code' => $errorCode,
            'errors' => (object) $errors,
            'correlation_id' => $correlationId,
            ...$extra,
        ], $status)->header('Correlation-Id', $correlationId);
    }
}
