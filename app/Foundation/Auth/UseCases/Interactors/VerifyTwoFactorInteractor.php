<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use RuntimeException;
use App\Foundation\Auth\Totp\LoginChallengeStore;
use App\Foundation\Auth\UseCases\Commands\VerifyTwoFactorCommand;
use App\Foundation\Auth\Exceptions\LoginChallengeNotFoundException;

/**
 * Completes a 2FA login (UC-1-005): resolves the opaque challenge, verifies the second
 * factor (TOTP or a single-use recovery code), and returns the authenticated user for
 * the controller to mint a token. Token-only — records no UserLoggedIn (mirrors
 * AuthenticateUserInteractor). The recovery path is the only event-sourced branch
 * (User2FARecoveryCodeUsed); the secret + code hashes live in the users columns
 * (Design-Y), never the event store.
 */
class VerifyTwoFactorInteractor
{
    public function __construct(
        private readonly LoginChallengeStore $challenges,
    ) {}

    public function execute(VerifyTwoFactorCommand $command): User
    {
        $challenge = $this->challenges->find($command->challengeId);

        if ($challenge === null) {
            throw new LoginChallengeNotFoundException;
        }

        throw new RuntimeException('Not implemented');
    }
}
