<?php

namespace App\Companies\Memberships\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Companies\Memberships\UseCases\Commands\AcceptInvitationCommand;
use App\Companies\Memberships\UseCases\Interactors\AcceptInvitationInteractor;

/**
 * Accept a team invitation via its emailed single-use token (UC-1-019).
 * Single-action by design — the declared exception to the multi-action
 * resource-controller convention (slice §4, session 106): the accept is a
 * guest-or-authenticated capability URL, not a company-context resource action.
 * The route mounts NO auth middleware; the user is resolved optionally via the
 * sanctum guard (guest → null; R9/D7 decides who must be authenticated).
 */
class AcceptInvitationController
{
    public function __invoke(Request $request, string $token, AcceptInvitationInteractor $interactor): JsonResponse
    {
        // The token is a route param, not body input (H6) — fold it into the
        // Command payload so one shape validates the whole request.
        $command = AcceptInvitationCommand::validateAndCreate([...$request->all(), 'token' => $token]);

        $interactor->execute($command, $request->user('sanctum'));

        return new JsonResponse(null, 204);
    }
}
