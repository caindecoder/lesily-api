<?php

namespace App\Companies\Memberships\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Companies\Memberships\UseCases\Commands\MembershipFilters;
use App\Companies\Memberships\IO\Http\Resources\MembershipCollection;
use App\Companies\Memberships\UseCases\Commands\ChangeMembershipRoleCommand;
use App\Companies\Memberships\UseCases\Interactors\ChangeMembershipRoleInteractor;
use App\Companies\Memberships\UseCases\Repositories\CompanyUserRepositoryInterface;

/**
 * The company's membership resource — the roster listing (UC-1-017) and
 * change-role (UC-1-023); remove (UC-1-024) joins here later in the slice.
 * Multi-action resource controller per the subscription-platform convention +
 * cd-users §5.
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

    public function update(Request $request, string $membershipId, ChangeMembershipRoleInteractor $interactor): JsonResponse
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $command = ChangeMembershipRoleCommand::validateAndCreate([
            'roleCode' => $request->input('roleCode'),
            'changedBy' => $actor->getKey(),
        ]);

        $interactor->execute($request->company(), $command, $membershipId);

        return response()->json();
    }
}
