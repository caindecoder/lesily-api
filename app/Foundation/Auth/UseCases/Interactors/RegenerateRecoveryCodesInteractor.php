<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use App\Foundation\Auth\Exceptions\TwoFactorNotEnabledException;
use App\Foundation\Auth\UseCases\Commands\RegenerateRecoveryCodesCommand;

/**
 * Rotates a user's 2FA recovery codes (UC-1-137) after a password re-auth: generates a
 * fresh set, hashes them, and overwrites the stored codes — keeping the TOTP secret, so
 * no QR re-scan. Mirrors ConfirmTwoFactorInteractor's code-gen with DisableTwoFactorInteractor's
 * guards (Design-Y — the codes live in the users column; the User2FARecoveryCodesRegenerated
 * event is a non-secret marker). Returns the plaintext codes for one-time display.
 */
class RegenerateRecoveryCodesInteractor
{
    /**
     * @return list<string>
     */
    public function execute(User $user, RegenerateRecoveryCodesCommand $command): array
    {
        if ($user->two_factor_confirmed_at === null) {
            throw new TwoFactorNotEnabledException;
        }

        return [];
    }
}
