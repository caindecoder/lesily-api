<?php

namespace App\Compliance\Dsar\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Compliance\Dsar\UseCases\Commands\DsarRequestFilters;
use App\Compliance\Dsar\IO\Http\Resources\DsarRequestResource;
use App\Compliance\Dsar\IO\Http\Resources\DsarRequestCollection;
use App\Compliance\Dsar\UseCases\Commands\SubmitDsarRequestCommand;
use App\Compliance\Dsar\UseCases\Interactors\ListDsarRequestsInteractor;
use App\Compliance\Dsar\UseCases\Interactors\SubmitDsarRequestInteractor;

class DsarRequestsController extends Controller
{
    public function index(Request $request, ListDsarRequestsInteractor $interactor): DsarRequestCollection
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        return new DsarRequestCollection(
            $interactor->execute(DsarRequestFilters::fromQuery($request), $actor, $request->company())
        );
    }

    public function store(Request $request, SubmitDsarRequestInteractor $interactor): JsonResponse
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $command = SubmitDsarRequestCommand::validateAndCreate([
            'subject_user_id' => $request->input('subject_user_id'),
            'companyId' => $request->company()->getKey(),
            'requestedByUserId' => $actor->getKey(),
        ]);

        $dsarRequest = $interactor->execute($command, $actor, AdminRequestContext::fromRequest($request));

        return (new DsarRequestResource($dsarRequest))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
