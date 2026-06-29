<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\UseCases\Interactors\RevokeSessionInteractor;

/**
 * Thin delivery appendix (E07) for DELETE /api/v1/me/sessions/{tokenId} — revoke one of
 * the authenticated user's own active sessions (UC-1-010 manage-sessions surface).
 * Delegates the user-scoped delete to RevokeSessionInteractor; returns 204.
 */
class RevokeSessionController extends Controller
{
    public function __invoke(Request $request, string $tokenId, RevokeSessionInteractor $interactor): Response
    {
        /** @var User $user */
        $user = $request->user();

        $interactor->execute($user, $tokenId);

        return response()->noContent();
    }
}
