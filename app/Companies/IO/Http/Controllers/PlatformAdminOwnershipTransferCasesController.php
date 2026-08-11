<?php

namespace App\Companies\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Companies\UseCases\Commands\OpenOwnershipTransferCaseCommand;
use App\Companies\UseCases\Commands\OwnershipTransferCaseAdminFilters;
use App\Companies\IO\Http\Resources\OwnershipTransferCaseAdminResource;
use App\Companies\UseCases\Commands\RejectOwnershipTransferCaseCommand;
use App\Companies\UseCases\Commands\VerifyOwnershipTransferCaseCommand;
use App\Companies\UseCases\Commands\ExecuteOwnershipTransferCaseCommand;
use App\Companies\IO\Http\Resources\OwnershipTransferCaseAdminCollection;
use App\Companies\UseCases\Interactors\OpenOwnershipTransferCaseInteractor;
use App\Companies\UseCases\Interactors\RejectOwnershipTransferCaseInteractor;
use App\Companies\UseCases\Interactors\VerifyOwnershipTransferCaseInteractor;
use App\Companies\UseCases\Interactors\ExecuteOwnershipTransferCaseInteractor;
use App\Companies\UseCases\Interactors\ReviewOwnershipTransferCaseQueueInteractor;
use App\Companies\UseCases\Interactors\ReviewOwnershipTransferCaseAsAdminInteractor;

/**
 * The platform-admin ownership-transfer case surface (slice-11R spec §3).
 * Cross-tenant by design: auth:sanctum + platform.admin only — no
 * auth.company, no permission gate (EnsurePermission needs a company context
 * this family deliberately lacks). Non-allowlisted callers are 404ed by the
 * middleware before any method here runs.
 */
class PlatformAdminOwnershipTransferCasesController
{
    public function index(Request $request, ReviewOwnershipTransferCaseQueueInteractor $interactor): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $page = $interactor->execute(
            OwnershipTransferCaseAdminFilters::fromQuery($request),
            $admin,
            AdminRequestContext::fromRequest($request),
        );

        return (new OwnershipTransferCaseAdminCollection($page))->response($request);
    }

    public function show(string $caseId, Request $request, ReviewOwnershipTransferCaseAsAdminInteractor $interactor): OwnershipTransferCaseAdminResource
    {
        /** @var User $admin */
        $admin = $request->user();

        return new OwnershipTransferCaseAdminResource($interactor->execute(
            $caseId,
            $admin,
            AdminRequestContext::fromRequest($request),
        ));
    }

    public function verify(string $caseId, Request $request, VerifyOwnershipTransferCaseInteractor $interactor): OwnershipTransferCaseAdminResource
    {
        /** @var User $admin */
        $admin = $request->user();

        $command = VerifyOwnershipTransferCaseCommand::validateAndCreate([
            ...$request->all(),
            'caseId' => $caseId,
        ]);

        return new OwnershipTransferCaseAdminResource($interactor->execute(
            $command,
            $admin,
            AdminRequestContext::fromRequest($request),
        ));
    }

    public function execute(string $caseId, Request $request, ExecuteOwnershipTransferCaseInteractor $interactor): OwnershipTransferCaseAdminResource
    {
        /** @var User $admin */
        $admin = $request->user();

        $command = ExecuteOwnershipTransferCaseCommand::validateAndCreate([
            'caseId' => $caseId,
        ]);

        return new OwnershipTransferCaseAdminResource($interactor->execute(
            $command,
            $admin,
            AdminRequestContext::fromRequest($request),
        ));
    }

    public function reject(string $caseId, Request $request, RejectOwnershipTransferCaseInteractor $interactor): OwnershipTransferCaseAdminResource
    {
        /** @var User $admin */
        $admin = $request->user();

        $command = RejectOwnershipTransferCaseCommand::validateAndCreate([
            ...$request->all(),
            'caseId' => $caseId,
        ]);

        return new OwnershipTransferCaseAdminResource($interactor->execute(
            $command,
            $admin,
            AdminRequestContext::fromRequest($request),
        ));
    }

    public function store(Request $request, OpenOwnershipTransferCaseInteractor $interactor): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $case = $interactor->execute(
            OpenOwnershipTransferCaseCommand::validateAndCreate($request),
            $admin,
            AdminRequestContext::fromRequest($request),
        );

        return (new OwnershipTransferCaseAdminResource($case))->response($request)->setStatusCode(201);
    }
}
