<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use RuntimeException;
use Illuminate\Support\Facades\Hash;
use App\Foundation\Auth\Totp\TotpVerifier;
use App\Foundation\Auth\Totp\LoginChallengeStore;
use App\Foundation\Auth\UseCases\Commands\VerifyTwoFactorCommand;
use App\Foundation\Auth\Exceptions\LoginChallengeNotFoundException;
use App\Foundation\Auth\Exceptions\TwoFactorVerificationFailedException;

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
        private readonly TotpVerifier $totp,
    ) {}

    public function execute(VerifyTwoFactorCommand $command): User
    {
        $challenge = $this->challenges->find($command->challengeId);

        if ($challenge === null) {
            throw new LoginChallengeNotFoundException;
        }

        $user = User::query()->findOrFail($challenge->userId);

        if ($this->totp->verify((string) $user->two_factor_secret, $command->code)) {
            throw new RuntimeException('Not implemented');
        }

        foreach ($user->two_factor_recovery_codes ?? [] as $hash) {
            if (Hash::check($command->code, $hash)) {
                throw new RuntimeException('Not implemented');
            }
        }

        $this->challenges->recordFailure($command->challengeId);

        throw new TwoFactorVerificationFailedException;
    }
}
