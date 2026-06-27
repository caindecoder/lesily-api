<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\Support\DeviceName;
use App\Foundation\Auth\UseCases\Commands\LoginCommand;
use App\Foundation\Auth\IO\Http\Resources\LoginResource;
use App\Foundation\Auth\UseCases\Results\ChallengeRequired;
use App\Foundation\Auth\UseCases\Interactors\LogInInteractor;
use App\Foundation\Auth\IO\Http\Resources\TwoFactorChallengeResource;

/**
 * Thin delivery appendix (E07) for POST /api/v1/auth/login. Validates the request into a
 * Command, delegates the credential check + 2FA decision + token mint to LogInInteractor,
 * and maps the sealed LoginOutcome to its HTTP shape — 202 (2FA challenge) or 200 (session
 * issued). No token mint and no business branching live here (relocated to the use case,
 * session 090 token-lifecycle refactor).
 */
class LoginController extends Controller
{
    public function __invoke(Request $request, LogInInteractor $interactor): JsonResponse
    {
        $outcome = $interactor->execute(LoginCommand::validateAndCreate(
            $request->merge(['deviceName' => DeviceName::resolve($request->input('deviceName'), $request)])
        ));

        if ($outcome instanceof ChallengeRequired) {
            return (new TwoFactorChallengeResource($outcome->challengeId, $outcome->expiresAt))
                ->response()
                ->setStatusCode(202);
        }

        // The only other outcome is SessionIssued — LogInInteractor returns the sealed
        // ChallengeRequired|SessionIssued union, so the type narrows here automatically.
        return (new LoginResource($outcome->user, $outcome->session->token, $outcome->session->expiresAt))
            ->response();
    }
}
