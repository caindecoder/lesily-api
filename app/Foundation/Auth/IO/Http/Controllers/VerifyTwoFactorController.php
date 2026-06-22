<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Config;
use App\Foundation\Auth\IO\Http\Resources\LoginResource;
use App\Foundation\Auth\UseCases\Commands\VerifyTwoFactorCommand;
use App\Foundation\Auth\UseCases\Interactors\VerifyTwoFactorInteractor;

/**
 * Thin delivery appendix (E07) for POST /api/v1/auth/2fa/verify — the second factor of
 * a 2FA login (UC-1-005). Validation lives on the Command, the TOTP/recovery check +
 * challenge consumption in the interactor; the controller only mints the Sanctum token
 * and shapes the 200 envelope. Token-only (no UserLoggedIn) — mirrors LoginController.
 */
class VerifyTwoFactorController extends Controller
{
    public function __invoke(Request $request, VerifyTwoFactorInteractor $interactor): JsonResponse
    {
        $command = VerifyTwoFactorCommand::validateAndCreate($request);
        $user = $interactor->execute($command);

        $token = $user->createToken('web', expiresAt: now()->addMinutes(Config::integer('sanctum.expiration')));

        return (new LoginResource($user, $token->plainTextToken, $token->accessToken->expires_at))->response();
    }
}
