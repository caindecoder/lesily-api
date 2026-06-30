<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use Laravel\Sanctum\PersonalAccessToken;
use App\Foundation\Auth\UseCases\Commands\ChangePasswordCommand;
use App\Foundation\Auth\UseCases\Interactors\ChangePasswordInteractor;

/**
 * Thin delivery appendix (E07) for PUT /api/v1/me/password — an authenticated user changing
 * their own password (UC-1-016). Validation lives on the Command, the current-password re-auth
 * + event-sourced write + other-session revoke in the interactor; the controller only resolves
 * the authenticated user + the current token id (the session to keep) and shapes the 204.
 */
class ChangePasswordController extends Controller
{
    public function __invoke(Request $request, ChangePasswordInteractor $interactor): Response
    {
        $command = ChangePasswordCommand::validateAndCreate($request);

        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken $current */
        $current = $user->currentAccessToken();

        $interactor->execute($user, $command, (string) $current->getKey());

        return response()->noContent();
    }
}
