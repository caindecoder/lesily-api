<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Companies\Memberships\Exceptions\AlreadyMemberException;
use App\Companies\Memberships\UseCases\Commands\InviteUserCommand;

class InviteUserInteractor
{
    public function execute(Company $company, InviteUserCommand $command): void
    {
        $userId = User::query()->where('email', $command->email)->value('id');

        if ($userId !== null && $company->users()->whereKey($userId)->exists()) {
            throw new AlreadyMemberException;
        }

        // Invitation creation (aggregate + event + inline mail) arrives with the happy-path lap.
    }
}
