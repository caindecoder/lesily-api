<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\Support\DeviceName;
use App\Foundation\Auth\IO\Http\Resources\LoginResource;
use App\Foundation\Auth\UseCases\Results\ChallengeRequired;
use App\Foundation\Auth\UseCases\Commands\GoogleOAuthCommand;
use App\Foundation\Auth\UseCases\Interactors\GoogleOAuthInteractor;
use App\Foundation\Auth\IO\Http\Resources\TwoFactorChallengeResource;

/** POST /api/v1/auth/google — the password login's delivery shape for a Google ID token. */
class GoogleOAuthController extends Controller
{
    public function __invoke(Request $request, GoogleOAuthInteractor $interactor): JsonResponse
    {
        $outcome = $interactor->execute(GoogleOAuthCommand::validateAndCreate(
            $request->merge(['deviceName' => DeviceName::resolve($request->input('deviceName'), $request)])
        ));

        if ($outcome instanceof ChallengeRequired) {
            return (new TwoFactorChallengeResource($outcome->challengeId, $outcome->expiresAt))
                ->response()
                ->setStatusCode(Response::HTTP_ACCEPTED);
        }

        return (new LoginResource($outcome->user, $outcome->session->token, $outcome->session->expiresAt))
            ->response();
    }
}
