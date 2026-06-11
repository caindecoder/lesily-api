<?php

namespace App\Foundation\Http\Services;

use Illuminate\Http\Request;
use App\Companies\Entities\Company;
use App\Foundation\Http\Exceptions\CompanyAccessDeniedException;

/**
 * Resolves the requested company from the X-Company-Context header for
 * AuthorizeCompany (cd-foundation-http step 4). A missing claim is denied as
 * COMPANY_ACCESS_DENIED; an unknown company surfaces the already-handled
 * ModelNotFoundException (404 RESOURCE_NOT_FOUND). URL-parameter and token-claim
 * resolution sources arrive with their first consumers (per the increment-03
 * deferral notes).
 */
class CompanyContextResolver
{
    public function resolve(Request $request): Company
    {
        $companyKey = $request->header('X-Company-Context');

        if ($companyKey === null) {
            throw new CompanyAccessDeniedException;
        }

        return Company::findOrFail($companyKey);
    }
}
