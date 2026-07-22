<?php

namespace App\Companies\OwnershipTransfers\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Companies\OwnershipTransfers\UseCases\Commands\OwnershipTransferFilters;
use App\Companies\OwnershipTransfers\UseCases\Commands\RequestOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\IO\Http\Resources\OwnershipTransferRequestResource;
use App\Companies\OwnershipTransfers\IO\Http\Resources\OwnershipTransferRequestCollection;
use App\Companies\OwnershipTransfers\UseCases\Interactors\RequestOwnershipTransferInteractor;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;

/**
 * The company's ownership-transfer resource — request (UC-1-028, SM-1-05 T1) +
 * the tenant reads (UC-1-126 history / UC-1-125 status). Withdraw (UC-1-114/115)
 * lands in a later slice-#11 task.
 */
class OwnershipTransfersController
{
    public function __construct(
        private readonly OwnershipTransferRequestRepositoryInterface $transfers,
    ) {}

    public function store(Request $request, RequestOwnershipTransferInteractor $interactor): JsonResponse
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        // Client fields + auth context validated at the delivery boundary
        // (RevokeInvitationCommand / ChangeMembershipRoleCommand precedent).
        $command = RequestOwnershipTransferCommand::validateAndCreate([
            ...$request->only(['targetUserId', 'reason', 'requestChannel']),
            'companyId' => $request->company()->getKey(),
            'currentOwnerUserId' => $actor->getKey(),
        ]);

        $transfer = $interactor->execute($request->company(), $command);

        return (new OwnershipTransferRequestResource($transfer))->response($request)->setStatusCode(201);
    }

    public function index(Request $request): OwnershipTransferRequestCollection
    {
        $filters = OwnershipTransferFilters::fromQuery($request);

        return new OwnershipTransferRequestCollection(
            $this->transfers->paginateForCompany((string) $request->company()->getKey(), $filters),
        );
    }
}
