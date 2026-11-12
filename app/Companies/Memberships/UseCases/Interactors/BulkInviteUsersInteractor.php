<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use App\Models\User;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Companies\Memberships\Services\InvitationAllowance;
use App\Companies\Memberships\Exceptions\AlreadyMemberException;
use App\Companies\Memberships\UseCases\Commands\InviteUserCommand;
use App\Companies\Memberships\UseCases\Results\BulkInviteRowResult;
use App\Companies\Memberships\UseCases\Commands\BulkInviteUsersCommand;
use App\Companies\Memberships\Exceptions\InvitationAllowanceExceededException;

/**
 * Bulk invite (UC-1-020): each row runs through the single invite (UC-1-018).
 */
class BulkInviteUsersInteractor
{
    public function __construct(
        private InvitationAllowance $allowance,
        private InviteUserInteractor $inviteUser,
    ) {}

    /**
     * @return list<BulkInviteRowResult>
     *
     * @throws InvitationAllowanceExceededException
     */
    public function execute(BulkInviteUsersCommand $command, User $actor, RequestOrigin $origin): array
    {
        $this->allowance->spend($actor, count($command->invitations));

        $invites = array_map(
            fn (array $row): InviteUserCommand => InviteUserCommand::from([...$row, 'company' => $command->company]),
            $command->invitations,
        );

        return array_map(fn (InviteUserCommand $invite): BulkInviteRowResult => $this->inviteRow($invite, $actor, $origin), $invites);
    }

    protected function inviteRow(InviteUserCommand $invite, User $actor, RequestOrigin $origin): BulkInviteRowResult
    {
        try {
            $this->inviteUser->invite($invite, $actor, $origin);
        } catch (AlreadyMemberException $refusal) {
            return new BulkInviteRowResult($invite->email, $invite->roleCode, $refusal->getMessage());
        }

        return new BulkInviteRowResult($invite->email, $invite->roleCode);
    }
}
