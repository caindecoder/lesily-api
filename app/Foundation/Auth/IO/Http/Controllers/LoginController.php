<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\UseCases\Commands\LoginCommand;
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
        $interactor->execute($command);

        // Happy-path token issuance + LoginResource built RED-first in UC-1-002 #5–#7.
        return response()->json([]);
    }
}
