<?php

namespace App\Companies\OwnershipTransfers\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Companies\OwnershipTransfers\UseCases\Commands\OwnershipTransferAdminFilters;
use App\Companies\OwnershipTransfers\IO\Http\Resources\OwnershipTransferRequestAdminResource;
use App\Companies\OwnershipTransfers\IO\Http\Resources\OwnershipTransferRequestAdminCollection;
use App\Companies\OwnershipTransfers\UseCases\Interactors\ReviewOwnershipTransferQueueInteractor;
use App\Companies\OwnershipTransfers\UseCases\Interactors\ReviewOwnershipTransferAsAdminInteractor;
use App\Companies\OwnershipTransfers\UseCases\Interactors\VerifyOwnershipTransferIdentityInteractor;

/**
 * The platform-admin cross-tenant ownership-transfer reads (UC-1-127) — behind
 * auth:sanctum + platform.admin (B2 INTERIM allowlist; 404 no-info-leak),
 * deliberately NO auth.company. The delivery layer translates and presents
 * only; the unscoped reads and their audit_trail rows live in the interactors
 * (E07 — the UC-1-122 precedent). Tasks 4/5/8 extend this class with the
 * verify / reject / execute transitions.
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

    public function verify(string $requestId, VerifyOwnershipTransferIdentityInteractor $interactor): OwnershipTransferRequestAdminResource
    {
        return new OwnershipTransferRequestAdminResource($interactor->execute($requestId));
    }
}
