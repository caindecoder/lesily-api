<?php

namespace App\Compliance\Dsar\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Compliance\Dsar\UseCases\Commands\SubmitDsarRequestCommand;
use App\Compliance\Dsar\UseCases\Interactors\SubmitDsarRequestInteractor;

class DsarRequestsController extends Controller
{
    public function store(Request $request, SubmitDsarRequestInteractor $interactor): JsonResponse
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $command = SubmitDsarRequestCommand::validateAndCreate([
            'subject_user_id' => $request->input('subject_user_id'),
            'companyId' => $request->company()->getKey(),
            'requestedByUserId' => $actor->getKey(),
        ]);

        $interactor->execute($request->company(), $actor, $command);

        return new JsonResponse(status: Response::HTTP_CREATED);
    }
}
