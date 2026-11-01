<?php

namespace App\Compliance\Dsar\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Dsar\UseCases\Commands\DsarRequestFilters;
use App\Compliance\Dsar\IO\Http\Resources\DsarRequestResource;
use App\Compliance\Dsar\IO\Http\Resources\DsarRequestCollection;
use App\Compliance\Dsar\UseCases\Commands\CancelDsarRequestCommand;
use App\Compliance\Dsar\UseCases\Commands\SubmitDsarRequestCommand;
use App\Compliance\Dsar\UseCases\Interactors\ShowDsarRequestInteractor;
use App\Compliance\Dsar\UseCases\Interactors\ListDsarRequestsInteractor;
use App\Compliance\Dsar\UseCases\Interactors\CancelDsarRequestInteractor;
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

    public function show(Request $request, string $requestId, ShowDsarRequestInteractor $interactor): DsarRequestResource
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        return new DsarRequestResource($interactor->execute($requestId, $actor, $request->company()));
    }

    public function store(Request $request, SubmitDsarRequestInteractor $interactor): JsonResponse
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $command = SubmitDsarRequestCommand::validateAndCreate([
            'subjectUserId' => $request->input('subjectUserId'),
            'company' => $request->company(),
            'requestedBy' => $actor,
        ]);

        $dsarRequest = $interactor->execute($command, $actor, RequestOrigin::fromRequest($request));

        return (new DsarRequestResource($dsarRequest))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function cancel(Request $request, string $requestId, CancelDsarRequestInteractor $interactor): DsarRequestResource
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $command = CancelDsarRequestCommand::validateAndCreate([
            'dsarRequest' => $requestId,
            'company' => $request->company(),
            'cancelledBy' => $actor,
        ]);

        return new DsarRequestResource($interactor->execute($command, $actor, RequestOrigin::fromRequest($request)));
    }
}
