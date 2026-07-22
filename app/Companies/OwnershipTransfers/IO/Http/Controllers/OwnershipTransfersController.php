<?php

namespace App\Companies\OwnershipTransfers\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Companies\OwnershipTransfers\IO\Http\Resources\OwnershipTransferRequestResource;
use App\Companies\OwnershipTransfers\UseCases\Interactors\RequestOwnershipTransferInteractor;

/**
 * The company's ownership-transfer resource — request (UC-1-028, SM-1-05 T1).
 * Later slice-#11 tasks add the reads (UC-1-125/126) and withdraw
 * (UC-1-114/115) actions.
 */
class OwnershipTransfersController
{
    public function store(Request $request, RequestOwnershipTransferInteractor $interactor): JsonResponse
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $transfer = $interactor->execute(
            $request->company(),
            $actor,
            $request->only(['targetUserId', 'reason', 'requestChannel']),
        );

        return (new OwnershipTransferRequestResource($transfer))->response($request)->setStatusCode(201);
    }
}
