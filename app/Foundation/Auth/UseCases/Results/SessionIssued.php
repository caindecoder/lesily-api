<?php

namespace App\Foundation\Auth\UseCases\Results;

use App\Models\User;

/**
 * LogInInteractor / VerifyTwoFactorInteractor outcome for a completed authentication: the minted
 * session plus the authenticated user. The controller renders this as a 200 (LoginResource).
 */
class SessionIssued extends LoginOutcome
{
    public function __construct(
        public readonly IssuedSession $session,
        public readonly User $user,
    ) {}
}
