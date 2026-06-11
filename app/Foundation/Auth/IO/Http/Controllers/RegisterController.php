<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerCommand;
use App\Foundation\Auth\IO\Http\Resources\RegisterSuccessResource;
use App\Foundation\Auth\UseCases\Interactors\RegisterOwnerInteractor;

/**
 * Thin delivery appendix (E07) for POST /api/v1/auth/register. Validation lives
 * on the Command, orchestration in the interactor; the controller only issues the
 * Sanctum token and shapes the 201 envelope.
 */
class RegisterController extends Controller
{
    public function __invoke(Request $request, RegisterOwnerInteractor $interactor): JsonResponse
    {
        $command = RegisterOwnerCommand::validateAndCreate($request);
        $result = $interactor->execute($command, (string) $request->ip());

        $owner = User::query()->findOrFail($result->userId);
        $token = $owner->createToken('web')->plainTextToken;

        return (new RegisterSuccessResource($owner, $token, $result->companyId))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
