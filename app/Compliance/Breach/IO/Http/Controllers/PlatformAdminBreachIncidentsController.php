<?php

namespace App\Compliance\Breach\IO\Http\Controllers;

use App\Models\User;
use RuntimeException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\UseCases\Commands\RecordBreachCommand;
use App\Compliance\Breach\UseCases\Interactors\RecordBreachInteractor;
use App\Compliance\Breach\UseCases\Commands\BreachIncidentAdminFilters;
use App\Compliance\Breach\UseCases\Commands\NotifyBreachCompaniesCommand;
use App\Compliance\Breach\IO\Http\Resources\BreachIncidentAdminCollection;
use App\Compliance\Breach\UseCases\Interactors\ReviewBreachQueueInteractor;
use App\Compliance\Breach\UseCases\Interactors\ReviewBreachAsAdminInteractor;
use App\Compliance\Breach\IO\Http\Resources\BreachIncidentAdminDetailResource;
use App\Compliance\Breach\UseCases\Interactors\NotifyBreachCompaniesInteractor;

class PlatformAdminBreachIncidentsController extends Controller
{
    public function store(Request $request, RecordBreachInteractor $interactor): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $breach = $interactor->execute(
            RecordBreachCommand::validateAndCreate($request),
            $admin,
            RequestOrigin::fromRequest($request),
        );

        return (new BreachIncidentAdminDetailResource($breach))
            ->response($request)
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function index(Request $request, ReviewBreachQueueInteractor $interactor): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $page = $interactor->execute(BreachIncidentAdminFilters::fromQuery($request), $admin, RequestOrigin::fromRequest($request));

        return (new BreachIncidentAdminCollection($page))->response($request);
    }

    public function show(Request $request, string $breachId, ReviewBreachAsAdminInteractor $interactor): BreachIncidentAdminDetailResource
    {
        /** @var User $admin */
        $admin = $request->user();

        return new BreachIncidentAdminDetailResource($interactor->execute($breachId, $admin, RequestOrigin::fromRequest($request)));
    }

    public function notifyCompanies(Request $request, string $breachId, NotifyBreachCompaniesInteractor $interactor): BreachIncidentAdminDetailResource
    {
        /** @var User $admin */
        $admin = $request->user();

        $command = NotifyBreachCompaniesCommand::validateAndCreate(['breachId' => $breachId]);

        return new BreachIncidentAdminDetailResource($interactor->execute($command, $admin, RequestOrigin::fromRequest($request)));
    }

    public function fileWithRegulator(): BreachIncidentAdminDetailResource
    {
        throw new RuntimeException('Not implemented');
    }
}
