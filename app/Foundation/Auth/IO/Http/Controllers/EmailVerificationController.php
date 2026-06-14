<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

/**
 * Thin delivery appendix (E07) for the signed email-verification link
 * (UC-1-001 alt-6a). The `signed` middleware is the credential — an invalid or
 * expired signature never reaches this action.
 */
class EmailVerificationController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash): JsonResponse
    {
        return response()->json(['message' => 'Email verified.']);
    }
}
