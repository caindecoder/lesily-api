<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use App\Models\User;
use RuntimeException;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Laravel\Sanctum\PersonalAccessToken;
use App\Foundation\Auth\UseCases\Commands\LogoutAllCommand;
use App\Foundation\Auth\UseCases\Interactors\RevokeAllSessionsInteractor;

/**
 * Thin delivery appendix (E07) for POST /api/v1/auth/logout-all — log out all of the user's
 * other devices after a password re-auth (UC-1-011). Validation lives on the Command, the
 * password check + revoke in the interactor; the controller only resolves the authenticated
 * user + the current token id (the session to preserve) and shapes the response.
 */
class LogoutAllController extends Controller
{
    public function __invoke(Request $request, RevokeAllSessionsInteractor $interactor): never
    {
        $command = LogoutAllCommand::validateAndCreate($request);

        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken $current */
        $current = $user->currentAccessToken();

        $interactor->execute($user, $command, (string) $current->getKey());

        throw new RuntimeException('Not implemented');
    }
}
