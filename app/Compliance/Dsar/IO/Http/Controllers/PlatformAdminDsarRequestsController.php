<?php

namespace App\Compliance\Dsar\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Dsar\IO\Http\Resources\DsarRequestAdminCollection;
use App\Compliance\Dsar\UseCases\Commands\ReviewDsarRequestQueueCommand;
use App\Compliance\Dsar\IO\Http\Resources\DsarRequestAdminDetailResource;
use App\Compliance\Dsar\UseCases\Interactors\ReviewDsarRequestQueueInteractor;
use App\Compliance\Dsar\UseCases\Interactors\ReviewDsarRequestAsAdminInteractor;

class PlatformAdminDsarRequestsController extends Controller
{
    public function index(Request $request, ReviewDsarRequestQueueInteractor $reviewDsarRequestQueueInteractor): JsonResponse
    {
        /** @var User $platformAdmin — guaranteed by auth:sanctum */
        $platformAdmin = $request->user();

        $dsarRequestQueue = $reviewDsarRequestQueueInteractor->execute(ReviewDsarRequestQueueCommand::fromQuery($request), $platformAdmin, RequestOrigin::fromRequest($request));

        return (new DsarRequestAdminCollection($dsarRequestQueue->dsarRequests))
            ->additional(['kpis' => ['open' => $dsarRequestQueue->open, 'overdue' => $dsarRequestQueue->overdue, 'due_in_7_days' => $dsarRequestQueue->dueInSevenDays]])
            ->response($request);
    }

    public function show(Request $request, string $requestId, ReviewDsarRequestAsAdminInteractor $reviewDsarRequestAsAdminInteractor): DsarRequestAdminDetailResource
    {
        /** @var User $platformAdmin — guaranteed by auth:sanctum */
        $platformAdmin = $request->user();

        return new DsarRequestAdminDetailResource($reviewDsarRequestAsAdminInteractor->execute($requestId, $platformAdmin, RequestOrigin::fromRequest($request)));
    }
}
