<?php

namespace App\Compliance\Breach\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\UseCases\Commands\AmendBreachCommand;
use App\Compliance\Breach\UseCases\Commands\RecordBreachCommand;
use App\Compliance\Breach\UseCases\Commands\ResolveBreachCommand;
use App\Compliance\Breach\UseCases\Commands\AddBreachCompanyCommand;
use App\Compliance\Breach\UseCases\Commands\ReviewBreachQueueCommand;
use App\Compliance\Breach\UseCases\Interactors\AmendBreachInteractor;
use App\Compliance\Breach\UseCases\Interactors\RecordBreachInteractor;
use App\Compliance\Breach\UseCases\Interactors\ResolveBreachInteractor;
use App\Compliance\Breach\UseCases\Commands\MarkBreachFalseAlarmCommand;
use App\Compliance\Breach\UseCases\Commands\NotifyBreachCompaniesCommand;
use App\Compliance\Breach\IO\Http\Resources\BreachIncidentAdminCollection;
use App\Compliance\Breach\UseCases\Interactors\AddBreachCompanyInteractor;
use App\Compliance\Breach\UseCases\Commands\FileBreachWithRegulatorCommand;
use App\Compliance\Breach\UseCases\Interactors\ReviewBreachQueueInteractor;
use App\Compliance\Breach\UseCases\Interactors\ReviewBreachAsAdminInteractor;
use App\Compliance\Breach\IO\Http\Resources\BreachIncidentAdminDetailResource;
use App\Compliance\Breach\UseCases\Interactors\MarkBreachFalseAlarmInteractor;
use App\Compliance\Breach\UseCases\Interactors\NotifyBreachCompaniesInteractor;
use App\Compliance\Breach\UseCases\Interactors\FileBreachWithRegulatorInteractor;

class PlatformAdminBreachIncidentsController extends Controller
{
    public function __construct(protected readonly FileBreachWithRegulatorInteractor $fileBreachWithRegulatorInteractor) {}

    public function store(Request $request, RecordBreachInteractor $recordBreachInteractor): JsonResponse
    {
        /** @var User $platformAdmin */
        $platformAdmin = $request->user();

        $breachIncident = $recordBreachInteractor->execute(
            RecordBreachCommand::validateAndCreate($request),
            $platformAdmin,
            RequestOrigin::fromRequest($request),
        );

        return (new BreachIncidentAdminDetailResource($breachIncident))
            ->response($request)
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function index(Request $request, ReviewBreachQueueInteractor $reviewBreachQueueInteractor): JsonResponse
    {
        /** @var User $platformAdmin */
        $platformAdmin = $request->user();

        $lengthAwarePaginator = $reviewBreachQueueInteractor->execute(ReviewBreachQueueCommand::fromQuery($request), $platformAdmin, RequestOrigin::fromRequest($request));

        return (new BreachIncidentAdminCollection($lengthAwarePaginator))->response($request);
    }

    public function show(Request $request, string $breachId, ReviewBreachAsAdminInteractor $reviewBreachAsAdminInteractor): BreachIncidentAdminDetailResource
    {
        /** @var User $platformAdmin */
        $platformAdmin = $request->user();

        return new BreachIncidentAdminDetailResource($reviewBreachAsAdminInteractor->execute($breachId, $platformAdmin, RequestOrigin::fromRequest($request)));
    }

    public function notifyCompanies(Request $request, string $breachId, NotifyBreachCompaniesInteractor $notifyBreachCompaniesInteractor): BreachIncidentAdminDetailResource
    {
        /** @var User $platformAdmin */
        $platformAdmin = $request->user();

        $notifyBreachCompaniesCommand = NotifyBreachCompaniesCommand::validateAndCreate(['breachId' => $breachId]);

        return new BreachIncidentAdminDetailResource($notifyBreachCompaniesInteractor->execute($notifyBreachCompaniesCommand, $platformAdmin, RequestOrigin::fromRequest($request)));
    }

    public function amend(Request $request, string $breachId, AmendBreachInteractor $amendBreachInteractor): BreachIncidentAdminDetailResource
    {
        /** @var User $platformAdmin */
        $platformAdmin = $request->user();

        $amendBreachCommand = AmendBreachCommand::fromRequest($request, $breachId);

        return new BreachIncidentAdminDetailResource($amendBreachInteractor->execute($amendBreachCommand, $platformAdmin, RequestOrigin::fromRequest($request)));
    }

    public function addCompany(Request $request, string $breachId, AddBreachCompanyInteractor $addBreachCompanyInteractor): BreachIncidentAdminDetailResource
    {
        /** @var User $platformAdmin */
        $platformAdmin = $request->user();

        $addBreachCompanyCommand = AddBreachCompanyCommand::validateAndCreate([...$request->all(), 'breachId' => $breachId]);

        return new BreachIncidentAdminDetailResource($addBreachCompanyInteractor->execute($addBreachCompanyCommand, $platformAdmin, RequestOrigin::fromRequest($request)));
    }

    public function resolve(Request $request, string $breachId, ResolveBreachInteractor $resolveBreachInteractor): BreachIncidentAdminDetailResource
    {
        /** @var User $platformAdmin */
        $platformAdmin = $request->user();

        $resolveBreachCommand = ResolveBreachCommand::validateAndCreate([...$request->all(), 'breachId' => $breachId]);

        return new BreachIncidentAdminDetailResource($resolveBreachInteractor->execute($resolveBreachCommand, $platformAdmin, RequestOrigin::fromRequest($request)));
    }

    public function markFalseAlarm(Request $request, string $breachId, MarkBreachFalseAlarmInteractor $markBreachFalseAlarmInteractor): BreachIncidentAdminDetailResource
    {
        /** @var User $platformAdmin */
        $platformAdmin = $request->user();

        $markBreachFalseAlarmCommand = MarkBreachFalseAlarmCommand::validateAndCreate([...$request->all(), 'breachId' => $breachId]);

        return new BreachIncidentAdminDetailResource($markBreachFalseAlarmInteractor->execute($markBreachFalseAlarmCommand, $platformAdmin, RequestOrigin::fromRequest($request)));
    }

    public function fileWithRegulator(Request $request, string $breachId, string $companyId): BreachIncidentAdminDetailResource
    {
        /** @var User $platformAdmin */
        $platformAdmin = $request->user();

        $fileBreachWithRegulatorCommand = FileBreachWithRegulatorCommand::validateAndCreate([
            'breachId' => $breachId,
            'company' => $companyId,
        ]);

        return new BreachIncidentAdminDetailResource(
            $this->fileBreachWithRegulatorInteractor->execute($fileBreachWithRegulatorCommand, $platformAdmin, RequestOrigin::fromRequest($request)),
        );
    }
}
