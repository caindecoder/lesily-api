<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use App\Foundation\Auth\UseCases\TokenRevoker;
use App\Foundation\Auth\UseCases\PasswordVerifier;
use App\Foundation\Auth\UseCases\Commands\ChangePasswordCommand;

/**
 * Change own password (UC-1-016): re-authenticate the user with their current password, then
 * event-source the new password and revoke their OTHER sessions. The current-password gate is
 * the use case's policy (E07) — it MUST reject before any write so a wrong password leaves the
 * password and every session intact (the Minimal Guarantee). The current token id is resolved by
 * the delivery layer and passed in, keeping this interactor free of request-scoped Sanctum state.
 */
class ChangePasswordInteractor
{
    public function __construct(
        private PasswordVerifier $passwordVerifier,
        private TokenRevoker $tokenRevoker,
    ) {}

    public function execute(User $user, ChangePasswordCommand $command, string $currentTokenId): void
    {
        $this->passwordVerifier->verify($user, $command->currentPassword);

        $this->tokenRevoker->revokeAllForUserExcept($user, $currentTokenId);
    }
}
