<?php

namespace App\Companies\Memberships\UseCases\Results;

use App\Models\User;
use App\Foundation\Auth\UseCases\Results\IssuedSession;

/**
 * The accept outcome for a brand-new email (UC-1-019 main flow): the account
 * was provisioned (UserCreatedFromInvitation genesis), the membership joined,
 * and a session issued — auto-login, mirroring register. The other arm of the
 * interactor's concrete union (s090 idiom).
 */
class NewUserJoined
{
    public function __construct(
        public readonly User $user,
        public readonly IssuedSession $session,
    ) {}
}
