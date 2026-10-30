<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use App\Foundation\Auth\Exceptions\GoogleNotLinkedException;
use App\Foundation\Auth\UseCases\Commands\UnlinkGoogleAccountCommand;

class UnlinkGoogleAccountInteractor
{
    public function execute(User $user, UnlinkGoogleAccountCommand $command, string $currentTokenId): void
    {
        if ($user->google_id === null) {
            throw new GoogleNotLinkedException;
        }
    }
}
