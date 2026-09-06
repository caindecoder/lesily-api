<?php

namespace App\Companies\Memberships\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Companies\Memberships\UseCases\Commands\InviteUserCommand;
use App\Companies\Memberships\IO\Http\Resources\InvitationResource;
use App\Companies\Memberships\UseCases\Commands\RevokeInvitationCommand;
use App\Companies\Memberships\UseCases\Interactors\InviteUserInteractor;
use App\Companies\Memberships\UseCases\Interactors\ResendInvitationInteractor;
use App\Companies\Memberships\UseCases\Interactors\RevokeInvitationInteractor;

/**
 * The company's invitation resource — invite (UC-1-018), resend (UC-1-021), and
 * revoke (UC-1-022). Multi-action resource controller per the subscription-platform
 * convention + cd-users §5.
 */
class InvitationsController
{
    public function store(Request $request, InviteUserInteractor $interactor): JsonResponse
    {
        $command = InviteUserCommand::validateAndCreate($request);

        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $invitation = $interactor->execute($request->company(), $actor, $command);

        return (new InvitationResource($invitation))->response($request)->setStatusCode(Response::HTTP_CREATED);
    }

    public function resend(Request $request, string $invitationId, ResendInvitationInteractor $interactor): JsonResponse
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $invitation = $interactor->execute($request->company(), $actor, $invitationId);

        return (new InvitationResource($invitation))->response($request)->setStatusCode(Response::HTTP_OK);
    }

    public function destroy(Request $request, string $invitationId, RevokeInvitationInteractor $interactor): Response
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $command = RevokeInvitationCommand::validateAndCreate([
            'revokedBy' => $actor->getKey(),
            'reason' => $request->input('reason'),
        ]);

        $interactor->execute($request->company(), $command, $invitationId);

        return response()->noContent();
    }
}
