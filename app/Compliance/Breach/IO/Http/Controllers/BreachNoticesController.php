<?php

namespace App\Compliance\Breach\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\UseCases\Commands\BreachNoticeFilters;
use App\Compliance\Breach\IO\Http\Resources\BreachNoticeResource;
use App\Compliance\Breach\IO\Http\Resources\BreachNoticeCollection;
use App\Compliance\Breach\UseCases\Commands\RequestBreachFilingCommand;
use App\Compliance\Breach\UseCases\Interactors\ListBreachNoticesInteractor;
use App\Compliance\Breach\UseCases\Interactors\RequestBreachFilingInteractor;

class BreachNoticesController extends Controller
{
    public function __construct(protected readonly RequestBreachFilingInteractor $requestFiling) {}

    public function index(Request $request, ListBreachNoticesInteractor $interactor): JsonResponse
    {
        $notices = $interactor->execute(BreachNoticeFilters::fromQuery($request), $request->company());

        return (new BreachNoticeCollection($notices))->response($request);
    }

    public function requestFiling(Request $request, string $companyId, string $breachId): BreachNoticeResource
    {
        /** @var User $owner — guaranteed by auth:sanctum */
        $owner = $request->user();

        $command = RequestBreachFilingCommand::validateAndCreate([
            'breachId' => $breachId,
            'company' => $companyId,
        ]);

        return new BreachNoticeResource($this->requestFiling->execute($command, $owner, RequestOrigin::fromRequest($request)));
    }
}
