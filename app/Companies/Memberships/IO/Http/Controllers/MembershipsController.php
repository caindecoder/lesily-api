<?php

namespace App\Companies\Memberships\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Companies\Memberships\UseCases\Commands\MembershipFilters;
use App\Companies\Memberships\IO\Http\Resources\MembershipResource;
use App\Companies\Memberships\IO\Http\Resources\MembershipCollection;
use App\Companies\Memberships\IO\Http\Resources\EmergencyContactResource;
use App\Companies\Memberships\UseCases\Commands\SetEmergencyContactCommand;
use App\Companies\Memberships\UseCases\Commands\ChangeMembershipRoleCommand;
use App\Companies\Memberships\UseCases\Commands\RemoveUserFromCompanyCommand;
use App\Companies\Memberships\UseCases\Interactors\SetEmergencyContactInteractor;
use App\Companies\Memberships\UseCases\Interactors\ChangeMembershipRoleInteractor;
use App\Companies\Memberships\UseCases\Interactors\RemoveUserFromCompanyInteractor;
use App\Companies\Memberships\UseCases\Repositories\CompanyUserRepositoryInterface;

/**
 * The company's membership resource — the roster listing (UC-1-017) and
 * change-role (UC-1-023); remove (UC-1-024) joins here later in the slice.
 * Multi-action resource controller per the subscription-platform convention +
 * cd-users §5.
 */
class MembershipsController
{
    public function __construct(private readonly CompanyUserRepositoryInterface $memberships) {}

    public function index(Request $request): MembershipCollection
    {
        $filters = MembershipFilters::fromQuery($request);

        return new MembershipCollection(
            $this->memberships->listForCompany($request->company()->getKey(), $filters)
        );
    }

    public function emergencyContact(Request $request): EmergencyContactResource
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $membership = $this->memberships->findWithEmergencyContact($request->company()->getKey(), $actor->getKey())
            ?? throw new ModelNotFoundException;

        return new EmergencyContactResource($membership);
    }

    public function setEmergencyContact(Request $request, SetEmergencyContactInteractor $interactor): EmergencyContactResource
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $command = SetEmergencyContactCommand::validateAndCreate($request->only(['name', 'email', 'phone', 'relationship']));

        return new EmergencyContactResource($interactor->execute($request->company(), $actor, $command));
    }

    public function update(Request $request, string $membershipId, ChangeMembershipRoleInteractor $interactor): JsonResponse
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();
        $company = $request->company();

        $command = ChangeMembershipRoleCommand::validateAndCreate([
            'roleCode' => $request->input('roleCode'),
            'changedBy' => $actor,
        ]);

        $interactor->execute($company, $command, $membershipId);

        // The 200 envelope echoes the updated roster row (R-E s112) — the same
        // aliased shape the roster lists, so the client updates in place. A row
        // removed between the write and this read answers the standard 404: the
        // membership is gone, the change already stands.
        $row = $this->memberships->findById($company->getKey(), $membershipId)
            ?? throw new ModelNotFoundException;

        return (new MembershipResource($row))->response($request)->setStatusCode(Response::HTTP_OK);
    }

    public function destroy(Request $request, string $membershipId, RemoveUserFromCompanyInteractor $interactor): Response
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $command = RemoveUserFromCompanyCommand::validateAndCreate([
            'removedBy' => $actor,
        ]);

        $interactor->execute($request->company(), $command, $membershipId);

        return response()->noContent();
    }
}
