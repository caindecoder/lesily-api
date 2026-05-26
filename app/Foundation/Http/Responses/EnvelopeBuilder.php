<?php

namespace App\Foundation\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EnvelopeBuilder
{
    /**
     * Builds the canonical Lesily error envelope per api-conventions §8.4.
     *
     * @param  array<string, mixed>  $extras  Optional per-handler fields:
     *                                        - 'errors' (array|object) — validation/structured error details (default: empty object)
     *                                        - any other key → merged at top level of the envelope (e.g., upgrade_to, user_role, retry_after)
     */
    public static function build(
        Request $request,
        int $status,
        string $errorCode,
        string $message,
        array $extras = []
    ): JsonResponse {
        $envelope = [
            'status' => 'error',
            'message' => $message,
            'error_code' => $errorCode,
            'errors' => (object) ($extras['errors'] ?? []),
            'correlation_id' => $request->attributes->get(
                'correlation_id',
                '00000000-0000-0000-0000-000000000000'
            ),
        ];

        $extraTopLevel = $extras;
        unset($extraTopLevel['errors']);

        return response()->json(array_merge($envelope, $extraTopLevel), $status);
    }
}
