<?php

namespace App\Companies\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Companies\IO\Http\Resources\CompanyResource;
use App\Companies\UseCases\Commands\CreateCompanyCommand;
use App\Companies\UseCases\Commands\UpdateCompanyCommand;
use App\Companies\UseCases\Interactors\CreateCompanyInteractor;
use App\Companies\UseCases\Interactors\UpdateCompanyDetailsInteractor;

/**
 * The company resource — details view (UC-1-032) and update (UC-1-033).
 * Repository-direct read (cd-companies §5): AuthorizeCompany resolves +
 * membership-checks the company upstream, so show only shapes it; update
 * refuses immutable fields at the command whitelist then records events through
 * the Company aggregate, returning the refreshed resource.
 */
class CompaniesController
{
    public function store(Request $request, CreateCompanyInteractor $interactor): JsonResponse
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $interactor->execute(
            CreateCompanyCommand::validateAndCreate([...$request->all(), 'ownerUserId' => $actor->getKey()]),
            $request->ip(),
        );

        return new JsonResponse(null, Response::HTTP_CREATED);
    }

    public function show(Request $request): CompanyResource
    {
        return new CompanyResource($request->company());
    }

    public function update(Request $request, UpdateCompanyDetailsInteractor $interactor): CompanyResource
    {
        $command = UpdateCompanyCommand::fromRequest($request);

        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $company = $interactor->execute($request->company(), $actor, $command);

        return new CompanyResource($company);
    }
}
