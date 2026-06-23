<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Foundation\Auth\Totp\RecoveryCodeGenerator;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\Auth\Exceptions\IncorrectPasswordException;
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
    public function __construct(
        private readonly RecoveryCodeGenerator $recoveryCodes,
    ) {}

    /**
     * @return list<string>
     */
    public function execute(User $user, RegenerateRecoveryCodesCommand $command): array
    {
        if ($user->two_factor_confirmed_at === null) {
            throw new TwoFactorNotEnabledException;
        }

        if (! Hash::check($command->password, $user->password)) {
            throw new IncorrectPasswordException;
        }

        $plaintextCodes = $this->recoveryCodes->generate();

        // Atomic: the direct recovery-code overwrite and the marker-event persist commit
        // together. The TOTP secret + two_factor_confirmed_at are untouched — only the
        // codes rotate (Design-Y; the marker carries no secret material).
        DB::transaction(function () use ($user, $plaintextCodes): void {
            $user->two_factor_recovery_codes = array_map(Hash::make(...), $plaintextCodes);
            $user->save();

            UserAggregate::retrieve($user->getKey())->regenerateRecoveryCodes()->persist();
        });

        return $plaintextCodes;
    }
}
