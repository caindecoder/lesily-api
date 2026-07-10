<?php

namespace App\Companies\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Companies\IO\Http\Resources\CompanyResource;
use App\Companies\UseCases\Commands\UpdateCompanyCommand;

/**
 * The company resource — details view (UC-1-032) and update (UC-1-033).
 * Repository-direct read (cd-companies §5): AuthorizeCompany resolves +
 * membership-checks the company upstream, so show only shapes it; update
 * records events through the Company aggregate.
 */
class CompaniesController
{
    public function show(Request $request): CompanyResource
    {
        return new CompanyResource($request->company());
    }

    public function update(Request $request, string $companyId): JsonResponse
    {
        UpdateCompanyCommand::fromRequest($request);

        return response()->json(['data' => []]);
    }
}
