<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\UseCases\Commands\LoginCommand;
use App\Foundation\Auth\IO\Http\Resources\LoginResource;
use App\Foundation\Auth\UseCases\Interactors\AuthenticateUserInteractor;

/**
 * Thin delivery appendix (E07) for POST /api/v1/auth/login. Validation lives on
 * the Command, the credential check in the interactor; the controller only issues
 * the Sanctum token and shapes the 200 envelope (mirrors RegisterController).
 */
class LoginController extends Controller
{
    public function __invoke(Request $request, AuthenticateUserInteractor $interactor): JsonResponse
    {
        $command = LoginCommand::validateAndCreate($request);
        $user = $interactor->execute($command);
        $token = $user->createToken(
            $command->deviceName ?? 'web',
            ['*'],
            now()->addMinutes((int) config('sanctum.expiration')),
        );

        return (new LoginResource($user, $token->plainTextToken, $token->accessToken->expires_at))->response();
    }
}
