<?php

namespace App\Foundation\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

class EnvelopeBuilder
{
    /**
     * @param  array<string, mixed>  $additional
     */
    public static function build(ApiErrorCode $code, string $message, array $additional = []): JsonResponse
    {
        $correlationId = Context::get('correlation_id');

        return response()->json([
            'status' => 'error',
            'message' => $message,
            'error_code' => $code->value,
            'errors' => (object) [],
            'correlation_id' => $correlationId,
            ...$additional,
        ], $code->status())->header('Correlation-Id', $correlationId);
    }
}
