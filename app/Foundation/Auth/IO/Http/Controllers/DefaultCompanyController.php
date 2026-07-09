<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;

/**
 * Thin delivery appendix (E07) for PUT/DELETE /api/v1/me/default-company — the
 * persisted default-company preference (UC-1-139, slice-companies B2/B3). The
 * live-membership guard and the plain users.default_company_id write live in
 * the interactors; the controller only shapes the 204s.
 */
class DefaultCompanyController extends Controller
{
    public function update(Request $request): Response
    {
        return response()->noContent();
    }
}
