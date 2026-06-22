<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Config;
use App\Foundation\Auth\Totp\LoginChallengeStore;
use App\Foundation\Auth\UseCases\Commands\LoginCommand;
use App\Foundation\Auth\IO\Http\Resources\LoginResource;
use App\Foundation\Auth\IO\Http\Resources\TwoFactorChallengeResource;
use App\Foundation\Auth\UseCases\Interactors\AuthenticateUserInteractor;

/**
 * Thin delivery appendix (E07) for POST /api/v1/auth/login. Validation lives on
 * the Command, the credential check in the interactor; the controller only issues
 * the Sanctum token and shapes the 200 envelope (mirrors RegisterController).
 */
class LoginController extends Controller
{
    public function __invoke(Request $request, AuthenticateUserInteractor $interactor, LoginChallengeStore $challenges): JsonResponse
    {
        $command = LoginCommand::validateAndCreate($request);
        $user = $interactor->execute($command);

        // 2FA gate (UC-1-002 alt-4a): an enrolled user gets a transient challenge, not
        // a token — login is suspended at "awaiting-2fa" until /2fa/verify (#03). The
        // existing 200 token path is untouched for everyone else (forward-compatible).
        if ($user->two_factor_confirmed_at !== null) {
            return (new TwoFactorChallengeResource(
                $challenges->create($user->id),
                now()->addMinutes(LoginChallengeStore::TTL_MINUTES),
            ))->response()->setStatusCode(202);
        }

        // Stamp the token's expiry explicitly so the column (and the returned
        // expiresAt) is authoritative — createToken leaves it null otherwise, and
        // config('sanctum.expiration') only governs the auth-time check.
        $token = $user->createToken('web', expiresAt: now()->addMinutes(Config::integer('sanctum.expiration')));

        return (new LoginResource($user, $token->plainTextToken, $token->accessToken->expires_at))->response();
    }
}
