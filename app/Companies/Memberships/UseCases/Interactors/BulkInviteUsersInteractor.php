<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use App\Models\User;
use App\Companies\Entities\Company;
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
    public function execute(Company $company, User $actor, BulkInviteUsersCommand $command): array
    {
        $this->allowance->spend($actor, count($command->invitations));

        return array_map(fn (array $row): BulkInviteRowResult => $this->inviteRow($company, $actor, $row), $command->invitations);
    }

    /**
     * @param  array{email: string, roleCode: string}  $row
     */
    protected function inviteRow(Company $company, User $actor, array $row): BulkInviteRowResult
    {
        try {
            $this->inviteUser->execute($company, $actor, InviteUserCommand::from($row));
        } catch (AlreadyMemberException $refusal) {
            return new BulkInviteRowResult($row['email'], $row['roleCode'], $refusal->getMessage());
        }

        return new BulkInviteRowResult($row['email'], $row['roleCode']);
    }
}
