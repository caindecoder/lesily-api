<?php

namespace App\Companies\Memberships\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Companies\Entities\Company;
use App\Companies\Memberships\UseCases\Commands\InviteUserCommand;
use App\Companies\Memberships\UseCases\Interactors\InviteUserInteractor;

/**
 * Invite a user to the company by email (UC-1-018). Single-action, matching the
 * codebase's per-endpoint controller convention (the design's multi-action
 * InvitationsController is reconciled to per-action controllers at slice close).
 */
class InviteUserController
{
    public function __invoke(Request $request, InviteUserInteractor $interactor): JsonResponse
    {
        $command = InviteUserCommand::validateAndCreate($request);

        /** @var Company $company — resolved + membership-validated by auth.company */
        $company = $request->attributes->get('company');

        $interactor->execute($company, $command);

        // The InvitationResource payload arrives with the happy-path lap.
        return response()->json([], 201);
    }
}
