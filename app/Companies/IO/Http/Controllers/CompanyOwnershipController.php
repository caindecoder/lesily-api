<?php

namespace App\Companies\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Companies\IO\Http\Resources\CompanyOwnerResource;
use App\Companies\UseCases\Commands\TransferCompanyOwnershipCommand;
use App\Companies\UseCases\Interactors\TransferCompanyOwnershipInteractor;

/**
 * The company's owner seat (UC-1-028): PATCH replaces the owner with an
 * existing member through the event-sourced transfer chain. AuthorizeCompany
 * resolves + membership-checks the company upstream; the permission gate keeps
 * every non-owner out; the interactor owns the locked transaction.
 */
class CompanyOwnershipController
{
    public function __invoke(Request $request, TransferCompanyOwnershipInteractor $interactor): CompanyOwnerResource
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $command = TransferCompanyOwnershipCommand::validateAndCreate([
            'newOwnerUserId' => $request->input('newOwnerUserId'),
            'transferredBy' => $actor->getKey(),
            'source' => TransferCompanyOwnershipCommand::SOURCE_SELF_SERVICE,
        ]);

        return new CompanyOwnerResource($interactor->execute($request->company(), $command, $actor->getKey()));
    }
}
