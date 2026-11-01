<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use App\Foundation\Auth\UseCases\Commands\SetDefaultCompanyCommand;

/**
 * Set the user's default company (UC-1-139) — a plain users.default_company_id
 * preference write (NOT event-sourced; slice-companies B2). The command's rule
 * admits only a live membership of this user (B3).
 */
class SetDefaultCompanyInteractor
{
    public function execute(User $user, SetDefaultCompanyCommand $command): void
    {
        $user->default_company_id = $command->company->getKey();
        $user->save();
    }
}
