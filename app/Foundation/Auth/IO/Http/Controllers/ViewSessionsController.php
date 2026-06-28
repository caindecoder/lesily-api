<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

/**
 * Thin delivery appendix (E07) for GET /api/v1/me/sessions — the authenticated
 * user's own active login sessions (UC-1-010). Stub return; the real listing is
 * driven in by the own-sessions test next.
 */
class ViewSessionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}
