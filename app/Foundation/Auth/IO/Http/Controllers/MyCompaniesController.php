<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\IO\Http\Resources\MyCompaniesCollection;
use App\Foundation\Auth\UseCases\Interactors\ListMyCompaniesInteractor;

/**
 * Thin delivery appendix (E07) for GET /api/v1/me/companies — the authenticated
 * user's own live company memberships (UC-1-034, the switcher list). Delegates
 * the "which companies" read to ListMyCompaniesInteractor; MyCompaniesCollection
 * formats them.
 */
class MyCompaniesController extends Controller
{
    public function __invoke(Request $request, ListMyCompaniesInteractor $interactor): MyCompaniesCollection
    {
        /** @var User $user */
        $user = $request->user();

        return new MyCompaniesCollection($interactor->execute($user));
    }
}
