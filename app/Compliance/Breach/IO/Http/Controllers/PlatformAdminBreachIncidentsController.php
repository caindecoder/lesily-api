<?php

namespace App\Compliance\Breach\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\UseCases\Commands\RecordBreachCommand;
use App\Compliance\Breach\UseCases\Interactors\RecordBreachInteractor;
use App\Compliance\Breach\IO\Http\Resources\BreachIncidentAdminDetailResource;

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
}
