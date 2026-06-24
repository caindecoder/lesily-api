<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\UseCases\Interactors\LogOutInteractor;

/**
 * Thin delivery appendix (E07) for POST /api/v1/auth/logout: delegates the current-session
 * revocation to LogOutInteractor and returns 204 (session 090 — the token delete moved out of
 * the controller into the use case).
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request, LogOutInteractor $interactor): Response
    {
        /** @var User $user */
        $user = $request->user();

        $interactor->execute($user);

        return response()->noContent();
    }
}
