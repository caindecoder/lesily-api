<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use RuntimeException;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

/**
 * Thin delivery appendix (E07) for GET /api/v1/me/companies — the authenticated
 * user's own live company memberships (UC-1-034, the switcher list).
 */
class MyCompaniesController extends Controller
{
    public function __invoke(Request $request): never
    {
        throw new RuntimeException('Not implemented');
    }
}
