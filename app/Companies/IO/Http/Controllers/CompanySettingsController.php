<?php

namespace App\Companies\IO\Http\Controllers;

use Illuminate\Http\Request;
use App\Companies\IO\Http\Resources\CompanySettingsResource;

/**
 * The company's settings view (UC-1-056 / 057) — the company AuthorizeCompany
 * resolved, shaped by CompanySettingsResource.
 */
class CompanySettingsController
{
    public function show(Request $request): CompanySettingsResource
    {
        return new CompanySettingsResource($request->company());
    }
}
