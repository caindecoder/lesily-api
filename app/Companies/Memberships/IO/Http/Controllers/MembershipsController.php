<?php

namespace App\Companies\Memberships\IO\Http\Controllers;

use Illuminate\Http\Request;
use App\Companies\Memberships\UseCases\Commands\MembershipFilters;
use App\Companies\Memberships\IO\Http\Resources\MembershipCollection;
use App\Companies\Memberships\UseCases\Repositories\CompanyUserRepositoryInterface;

/**
 * The company's membership resource — the roster listing (UC-1-017); change-role
 * (UC-1-023) and remove (UC-1-024) join here later in the slice. Multi-action
 * resource controller per the subscription-platform convention + cd-users §5.
 */
class MembershipsController
{
    public function index(Request $request, CompanyUserRepositoryInterface $memberships): MembershipCollection
    {
        $filters = MembershipFilters::fromQuery($request);

        return new MembershipCollection(
            $memberships->listForCompany($request->company()->getKey(), $filters)
        );
    }
}
