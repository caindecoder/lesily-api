<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use Laravel\Sanctum\PersonalAccessToken;
use App\Foundation\Auth\UseCases\Commands\DisableTwoFactorCommand;
use App\Foundation\Auth\UseCases\Interactors\DisableTwoFactorInteractor;

/**
 * Thin delivery appendix (E07) for DELETE /api/v1/auth/2fa — disabling 2FA after a
 * password re-auth (UC-1-007). Validation lives on the Command, the password check +
 * credential wipe + marker + other-session revoke in the interactor; the controller only
 * resolves the authenticated user + the current token id (the session to keep) and shapes the 204.
 */
class DisableTwoFactorController extends Controller
{
    public function __invoke(Request $request, DisableTwoFactorInteractor $interactor): Response
    {
        $command = DisableTwoFactorCommand::validateAndCreate($request);

        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken $current */
        $current = $user->currentAccessToken();

        $interactor->execute($user, $command, $current->getKey());

        return response()->noContent();
    }
}
