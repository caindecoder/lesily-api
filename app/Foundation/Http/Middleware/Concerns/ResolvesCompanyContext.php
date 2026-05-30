<?php

namespace App\Foundation\Http\Middleware\Concerns;

use Illuminate\Http\Request;
use App\Companies\Entities\Company;

/**
 * Resolves the current company from the X-Company-Context header for the two-gate
 * middleware. Chunk-1 stub of the resolution the future AuthorizeCompany middleware
 * (cd-foundation-http step 4) will own; a missing/unknown company surfaces the
 * already-handled ModelNotFoundException (404).
 */
trait ResolvesCompanyContext
{
    protected function resolveCompany(Request $request): Company
    {
        return Company::findOrFail($request->header('X-Company-Context'));
    }
}
