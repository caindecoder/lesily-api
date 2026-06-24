<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerCommand;
use App\Foundation\Auth\IO\Http\Resources\RegisterSuccessResource;
use App\Foundation\Auth\UseCases\Interactors\RegisterOwnerInteractor;

/**
 * Thin delivery appendix (E07) for POST /api/v1/auth/register. Validation lives on the
 * Command, the orchestration + read-model materialisation + token mint in the interactor;
 * the controller only shapes the 201 envelope from the RegisterResult (session 090 — the
 * findOrFail re-query and createToken moved into RegisterOwnerInteractor).
 */
class RegisterController extends Controller
{
    public function __invoke(Request $request, RegisterOwnerInteractor $interactor): JsonResponse
    {
        $result = $interactor->execute(RegisterOwnerCommand::validateAndCreate($request), (string) $request->ip());

        return (new RegisterSuccessResource($result->user, $result->session->token, $result->companyId))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
