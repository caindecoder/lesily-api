<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use App\Models\User;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Companies\Memberships\Services\InvitationAllowance;
use App\Companies\Memberships\UseCases\Audit\BulkInviteSent;
use App\Companies\Memberships\UseCases\Audit\InvitationAudit;
use App\Companies\Memberships\Exceptions\AlreadyMemberException;
use App\Companies\Memberships\UseCases\Commands\InviteUserCommand;
use App\Companies\Memberships\UseCases\Results\BulkInviteRowResult;
use App\Companies\Memberships\UseCases\Commands\BulkInviteUsersCommand;
use App\Companies\Memberships\Exceptions\InvitationAllowanceExceededException;

/**
 * Bulk invite (UC-1-020): each row runs through the single invite (UC-1-018), each
 * committing with its own audit row; the batch row follows, counting the rows sent.
 */
class BulkInviteUsersInteractor
{
    public function __construct(
        private InvitationAllowance $allowance,
        private InviteUserInteractor $inviteUser,
        private InvitationAudit $audit,
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

        $results = array_map(fn (InviteUserCommand $invite): BulkInviteRowResult => $this->inviteRow($invite, $actor, $origin), $invites);

        $sent = count(array_filter($results, fn (BulkInviteRowResult $result): bool => $result->wasSent()));
        if ($sent > 0) {
            $this->audit->bulkInviteSent(new BulkInviteSent($command->company, $sent), $actor, $origin);
        }

        return $results;
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
