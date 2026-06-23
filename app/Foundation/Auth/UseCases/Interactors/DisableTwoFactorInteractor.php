<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\Auth\Exceptions\IncorrectPasswordException;
use App\Foundation\Auth\Exceptions\TwoFactorNotEnabledException;
use App\Foundation\Auth\UseCases\Commands\DisableTwoFactorCommand;

/**
 * Disables 2FA on an account (UC-1-007) after a password re-auth. Mirrors
 * ConfirmTwoFactorInteractor inverted: the credential columns are wiped directly
 * (Design-Y — secrets live outside the event store) while the User aggregate records
 * the non-secret User2FADisabled marker (whose projector nulls two_factor_confirmed_at),
 * both inside one transaction so a marker can never outrun its column wipe.
 */
class DisableTwoFactorInteractor
{
    public function execute(User $user, DisableTwoFactorCommand $command): void
    {
        if ($user->two_factor_confirmed_at === null) {
            throw new TwoFactorNotEnabledException;
        }

        if (! Hash::check($command->password, $user->password)) {
            throw new IncorrectPasswordException;
        }

        // Atomic: the direct credential wipe and the marker-event persist (whose projector
        // nulls two_factor_confirmed_at) commit together, so a marker can never outrun its
        // column wipe — and a wrong password above leaves 2FA fully intact (Minimal Guarantee).
        DB::transaction(function () use ($user): void {
            $user->two_factor_secret = null;
            $user->two_factor_recovery_codes = null;
            $user->save();

            UserAggregate::retrieve($user->getKey())->disableTwoFactor()->persist();
        });
    }
}
