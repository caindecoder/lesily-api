<?php

namespace App\Companies\Memberships\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Foundation\Auth\Support\DeviceName;
use App\Foundation\Auth\IO\Http\Resources\LoginResource;
use App\Companies\Memberships\UseCases\Results\NewUserJoined;
use App\Companies\Memberships\IO\Http\Resources\MembershipResource;
use App\Companies\Memberships\UseCases\Commands\AcceptInvitationCommand;
use App\Companies\Memberships\UseCases\Interactors\AcceptInvitationInteractor;
use App\Companies\Memberships\UseCases\Repositories\CompanyUserRepositoryInterface;

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
    public function __construct(private readonly CompanyUserRepositoryInterface $memberships) {}

    public function __invoke(Request $request, string $token, AcceptInvitationInteractor $interactor): JsonResponse
    {
        // The token is a route param, not body input (H6) — fold it into the
        // Command payload so one shape validates the whole request.
        $command = AcceptInvitationCommand::validateAndCreate([...$request->all(), 'token' => $token]);

        $outcome = $interactor->execute(
            $command,
            $request->user('sanctum'),
            // UA-derived like register — the accept contract has no deviceName field (H6).
            DeviceName::resolve(null, $request),
        );

        // New user: 201 auto-login (H5) — their session was just minted.
        if ($outcome instanceof NewUserJoined) {
            return (new LoginResource($outcome->user, $outcome->session->token, $outcome->session->expiresAt))
                ->response($request)->setStatusCode(201);
        }

        // Existing user: 200, no token minted (H5). The envelope echoes the
        // activated roster row — the change-role echo's shape (R-E s112).
        $row = $this->memberships->findById($outcome->membership->company_id, $outcome->membership->getKey());

        return (new MembershipResource($row))->response($request)->setStatusCode(200);
    }
}
