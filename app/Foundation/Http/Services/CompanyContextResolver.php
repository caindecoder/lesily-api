<?php

namespace App\Foundation\Http\Services;

use Illuminate\Http\Request;
use App\Companies\Entities\Company;
use App\Foundation\Http\Exceptions\CompanyAccessDeniedException;

/**
 * Resolves the requested company for AuthorizeCompany (cd-foundation-http step 4):
 * the {companyId} route parameter first, else the X-Company-Context header — on
 * param routes the URL is authoritative and a disagreeing header is ignored
 * (slice-companies R8; first param consumer = UC-1-032). A missing claim is denied
 * as COMPANY_ACCESS_DENIED; an unknown company surfaces the already-handled
 * ModelNotFoundException (404 RESOURCE_NOT_FOUND). The token-claim source is
 * deferred with company-scoped tokens (OQ-1-134).
 */
class CompanyContextResolver
{
    public function resolve(Request $request): Company
    {
        $companyKey = $request->route('companyId') ?? $request->header('X-Company-Context');

        if (! is_string($companyKey) || $companyKey === '') {
            throw new CompanyAccessDeniedException;
        }

        return Company::findOrFail($companyKey);
    }
}
