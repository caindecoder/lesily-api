<?php

namespace App\Compliance\Dsar\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Dsar\IO\Http\Resources\DsarRequestResource;
use App\Compliance\Dsar\IO\Http\Resources\DsarRequestCollection;
use App\Compliance\Dsar\UseCases\Commands\ListDsarRequestsCommand;
use App\Compliance\Dsar\UseCases\Commands\CancelDsarRequestCommand;
use App\Compliance\Dsar\UseCases\Commands\SubmitDsarRequestCommand;
use App\Compliance\Dsar\UseCases\Interactors\ShowDsarRequestInteractor;
use App\Compliance\Dsar\UseCases\Interactors\ListDsarRequestsInteractor;
use App\Compliance\Dsar\UseCases\Interactors\CancelDsarRequestInteractor;
use App\Compliance\Dsar\UseCases\Interactors\SubmitDsarRequestInteractor;

class DsarRequestsController extends Controller
{
    public function index(Request $request, ListDsarRequestsInteractor $listDsarRequestsInteractor): DsarRequestCollection
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        return new DsarRequestCollection(
            $listDsarRequestsInteractor->execute(ListDsarRequestsCommand::fromQuery($request), $actor, $request->company())
        );
    }

    public function show(Request $request, string $requestId, ShowDsarRequestInteractor $showDsarRequestInteractor): DsarRequestResource
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        return new DsarRequestResource($showDsarRequestInteractor->execute($requestId, $actor, $request->company()));
    }

    public function store(Request $request, SubmitDsarRequestInteractor $submitDsarRequestInteractor): JsonResponse
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $submitDsarRequestCommand = SubmitDsarRequestCommand::validateAndCreate([
            'subjectUserId' => $request->input('subjectUserId'),
            'company' => $request->company(),
            'requestedBy' => $actor,
        ]);

        $dsarRequest = $submitDsarRequestInteractor->execute($submitDsarRequestCommand, $actor, RequestOrigin::fromRequest($request));

        return (new DsarRequestResource($dsarRequest))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function cancel(Request $request, string $requestId, CancelDsarRequestInteractor $cancelDsarRequestInteractor): DsarRequestResource
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $cancelDsarRequestCommand = CancelDsarRequestCommand::validateAndCreate([
            'dsarRequest' => $requestId,
            'company' => $request->company(),
            'cancelledBy' => $actor,
        ]);

        return new DsarRequestResource($cancelDsarRequestInteractor->execute($cancelDsarRequestCommand, $actor, RequestOrigin::fromRequest($request)));
    }
}
