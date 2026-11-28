<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\Support\DeviceName;
use App\Foundation\Auth\IO\Http\Resources\LoginResource;
use App\Foundation\Auth\UseCases\Commands\VerifyTwoFactorCommand;
use App\Foundation\Auth\UseCases\Interactors\VerifyTwoFactorInteractor;

/**
 * Thin delivery appendix (E07) for POST /api/v1/auth/2fa/verify — the second factor of
 * a 2FA login (UC-1-005). Validation lives on the Command, the TOTP/recovery check +
 * challenge consumption + the token mint (via SessionIssuer) in the interactor; the controller
 * only shapes the 200 envelope from the SessionIssued outcome. Token-only — mirrors LoginController.
 */
class VerifyTwoFactorController extends Controller
{
    public function __invoke(Request $request, VerifyTwoFactorInteractor $verifyTwoFactorInteractor): JsonResponse
    {
        $sessionIssued = $verifyTwoFactorInteractor->execute(VerifyTwoFactorCommand::validateAndCreate(
            $request->merge(['deviceName' => DeviceName::resolve($request->input('deviceName'), $request)])
        ));

        return (new LoginResource($sessionIssued->user, $sessionIssued->issuedSession->token, $sessionIssued->issuedSession->expiresAt))->response();
    }
}
