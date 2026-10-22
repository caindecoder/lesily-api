<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;

class ResendVerificationEmailInteractor
{
    public function execute(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->sendEmailVerificationNotification();
    }
}
