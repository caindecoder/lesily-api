<?php

namespace App\Companies\Memberships\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Companies\Entities\CompanyUser;
use App\Companies\Memberships\UseCases\Commands\MembershipFilters;
use App\Companies\Memberships\UseCases\Repositories\CompanyUserRepositoryInterface;

/**
 * The company's membership resource — the roster listing (UC-1-017); change-role
 * (UC-1-023) and remove (UC-1-024) join here later in the slice. Multi-action
 * resource controller per the subscription-platform convention + cd-users §5.
 */
class MembershipsController
{
    public function index(Request $request, CompanyUserRepositoryInterface $memberships): JsonResponse
    {
        $filters = MembershipFilters::fromQuery($request);

        $roster = $memberships->listForCompany($request->company()->getKey(), $filters);

        return response()->json([
            'data' => $roster->map(fn (CompanyUser $membership): array => [
                'user' => ['id' => $membership->user_id],
            ]),
        ]);
    }
}
