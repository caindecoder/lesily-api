<?php

namespace App\Compliance\Dsar\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Compliance\Dsar\UseCases\Commands\DsarRequestAdminFilters;
use App\Compliance\Dsar\IO\Http\Resources\DsarRequestAdminCollection;
use App\Compliance\Dsar\IO\Http\Resources\DsarRequestAdminDetailResource;
use App\Compliance\Dsar\UseCases\Interactors\ReviewDsarRequestQueueInteractor;
use App\Compliance\Dsar\UseCases\Interactors\ReviewDsarRequestAsAdminInteractor;

class PlatformAdminDsarRequestsController extends Controller
{
    public function index(Request $request, ReviewDsarRequestQueueInteractor $interactor): JsonResponse
    {
        /** @var User $admin — guaranteed by auth:sanctum */
        $admin = $request->user();

        $queue = $interactor->execute(DsarRequestAdminFilters::fromQuery($request), $admin, AdminRequestContext::fromRequest($request));

        return (new DsarRequestAdminCollection($queue->page))
            ->additional(['kpis' => ['open' => $queue->open, 'overdue' => $queue->overdue, 'due_in_7_days' => $queue->dueInSevenDays]])
            ->response($request);
    }

    public function show(Request $request, string $requestId, ReviewDsarRequestAsAdminInteractor $interactor): DsarRequestAdminDetailResource
    {
        /** @var User $admin — guaranteed by auth:sanctum */
        $admin = $request->user();

        return new DsarRequestAdminDetailResource($interactor->execute($requestId, $admin, AdminRequestContext::fromRequest($request)));
    }
}
