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
        return self::buildWithStatus($code->status(), $code, $message, $additional);
    }

    /**
     * Build the §S21 error envelope with an explicit HTTP status — used when the
     * status is not fixed by the error code (e.g. the generic HTTP_ERROR fallback
     * preserves an unmapped HttpException's own getStatusCode()).
     *
     * @param  array<string, mixed>  $additional
     */
    public static function buildWithStatus(int $status, ApiErrorCode $code, string $message, array $additional = []): JsonResponse
    {
        $correlationId = Context::get('correlation_id');

        return response()->json([
            'status' => 'error',
            'message' => $message,
            'error_code' => $code->value,
            // Cast forces an empty map to serialize as JSON {} (not []), so the
            // errors field is always an object for strict client schemas. A
            // validation error overrides this via $additional['errors'].
            'errors' => (object) [],
            'correlation_id' => $correlationId,
            ...$additional,
        ], $status)->header('Correlation-Id', $correlationId);
    }
}
