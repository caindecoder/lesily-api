<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Foundation\Auth\UseCases\TokenRevoker;
use App\Foundation\Auth\UseCases\PasswordVerifier;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\Auth\Exceptions\TwoFactorNotEnabledException;
use App\Foundation\Auth\UseCases\Commands\DisableTwoFactorCommand;

/**
 * Disables 2FA on an account (UC-1-007) after a password re-auth. Mirrors
 * ConfirmTwoFactorInteractor inverted: the credential columns are wiped directly
 * (Design-Y — secrets live outside the event store) while the User aggregate records
 * the non-secret User2FADisabled marker (whose projector nulls two_factor_confirmed_at),
 * both inside one transaction so a marker can never outrun its column wipe. Disabling a
 * second factor weakens the account, so on success the user's OTHER sessions are revoked
 * synchronously while the current one survives (OQ-1-120, via the shared TokenRevoker).
 */
class DisableTwoFactorInteractor
{
    public function __construct(
        private TokenRevoker $tokenRevoker,
        private PasswordVerifier $passwordVerifier,
    ) {}

    public function execute(User $user, DisableTwoFactorCommand $command, string $currentTokenId): void
    {
        if ($user->two_factor_confirmed_at === null) {
            throw new TwoFactorNotEnabledException;
        }

        $this->passwordVerifier->verify($user, $command->password);

        // Atomic: the direct credential wipe and the marker-event persist (whose projector
        // nulls two_factor_confirmed_at) commit together, so a marker can never outrun its
        // column wipe — and a wrong password above leaves 2FA fully intact (Minimal Guarantee).
        DB::transaction(function () use ($user): void {
            $user->two_factor_secret = null;
            $user->two_factor_recovery_codes = null;
            $user->save();

            UserAggregate::retrieve($user->getKey())->disableTwoFactor()->persist();
        });

        // After the disable commits, invalidate every other device immediately (OQ-1-120):
        // a weakened second factor must not leave stale sessions alive. Token-table side-effect
        // only — the revoke count isn't surfaced (the endpoint stays 204) and the current session
        // is retained. Runs only on success — a refused disable (guarded above) revokes nothing.
        $this->tokenRevoker->revokeAllForUserExcept($user, $currentTokenId);
    }
}
