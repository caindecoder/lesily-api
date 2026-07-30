<?php

namespace App\Companies\OwnershipTransfers\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Companies\OwnershipTransfers\UseCases\Commands\OwnershipTransferAdminFilters;
use App\Companies\OwnershipTransfers\UseCases\Commands\RejectOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\UseCases\Commands\ExecuteOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\UseCases\Interactors\RejectOwnershipTransferInteractor;
use App\Companies\OwnershipTransfers\IO\Http\Resources\OwnershipTransferRequestAdminResource;
use App\Companies\OwnershipTransfers\UseCases\Interactors\ExecuteOwnershipTransferInteractor;
use App\Companies\OwnershipTransfers\UseCases\Commands\VerifyOwnershipTransferIdentityCommand;
use App\Companies\OwnershipTransfers\IO\Http\Resources\OwnershipTransferRequestAdminCollection;
use App\Companies\OwnershipTransfers\UseCases\Interactors\ReviewOwnershipTransferQueueInteractor;
use App\Companies\OwnershipTransfers\UseCases\Interactors\ReviewOwnershipTransferAsAdminInteractor;
use App\Companies\OwnershipTransfers\UseCases\Interactors\VerifyOwnershipTransferIdentityInteractor;

/**
 * The platform-admin cross-tenant ownership-transfer surface — the UC-1-127
 * reads plus the verify (T2), reject (T3/T5) and execute (T4) transitions —
 * behind auth:sanctum + platform.admin (B2 INTERIM allowlist; 404
 * no-info-leak), deliberately NO auth.company. The delivery layer translates
 * and presents only; the unscoped reads, the guards and their audit_trail
 * rows live in the interactors (E07 — the UC-1-122 precedent).
 */
class PlatformAdminOwnershipTransfersController
{
    public function index(Request $request, ReviewOwnershipTransferQueueInteractor $interactor): OwnershipTransferRequestAdminCollection
    {
        /** @var User $admin — guaranteed by auth:sanctum */
        $admin = $request->user();

        return new OwnershipTransferRequestAdminCollection(
            $interactor->execute(
                OwnershipTransferAdminFilters::fromQuery($request),
                $admin,
                AdminRequestContext::fromRequest($request),
            )
        );
    }

    public function show(string $requestId, Request $request, ReviewOwnershipTransferAsAdminInteractor $interactor): OwnershipTransferRequestAdminResource
    {
        /** @var User $admin — guaranteed by auth:sanctum */
        $admin = $request->user();

        return new OwnershipTransferRequestAdminResource(
            $interactor->execute($requestId, $admin, AdminRequestContext::fromRequest($request))
        );
    }

    public function verify(string $requestId, Request $request, VerifyOwnershipTransferIdentityInteractor $interactor): OwnershipTransferRequestAdminResource
    {
        /** @var User $admin — guaranteed by auth:sanctum */
        $admin = $request->user();

        // Client fields + auth context validated at the delivery boundary
        // (the OwnershipTransfersController@store precedent).
        $command = VerifyOwnershipTransferIdentityCommand::validateAndCreate([
            ...$request->only(['verificationNotes', 'verificationArtefacts']),
            'verifiedBy' => $admin->getKey(),
        ]);

        return new OwnershipTransferRequestAdminResource($interactor->execute($requestId, $command));
    }

    public function reject(string $requestId, Request $request, RejectOwnershipTransferInteractor $interactor): OwnershipTransferRequestAdminResource
    {
        /** @var User $admin — guaranteed by auth:sanctum */
        $admin = $request->user();

        $command = RejectOwnershipTransferCommand::validateAndCreate([
            ...$request->only(['reason', 'rejectionReasonCode']),
            'rejectedBy' => $admin->getKey(),
        ]);

        return new OwnershipTransferRequestAdminResource($interactor->execute($requestId, $command));
    }

    public function execute(string $requestId, Request $request, ExecuteOwnershipTransferInteractor $interactor): OwnershipTransferRequestAdminResource
    {
        /** @var User $admin — guaranteed by auth:sanctum */
        $admin = $request->user();

        $command = ExecuteOwnershipTransferCommand::validateAndCreate([
            ...$request->only(['reason']),
            'executedBy' => $admin->getKey(),
        ]);

        return new OwnershipTransferRequestAdminResource($interactor->execute($requestId, $command));
    }
}
