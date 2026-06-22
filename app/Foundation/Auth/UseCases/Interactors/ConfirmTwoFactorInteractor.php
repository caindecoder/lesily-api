<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use App\Foundation\Auth\Totp\TotpVerifier;
use App\Foundation\Auth\Totp\RecoveryCodeGenerator;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\Auth\Exceptions\InvalidTwoFactorCodeException;
use App\Foundation\Auth\UseCases\Commands\ConfirmTwoFactorCommand;
use App\Foundation\Auth\Exceptions\TwoFactorSetupNotStartedException;

/**
 * Confirms 2FA enrollment (UC-1-006 step 5): verify the submitted TOTP code against
 * the secret staged at enable, then enable 2FA. The secret + hashed recovery codes
 * are written directly to the users row (Design-Y — credentials live outside the
 * event store); the User aggregate records only the non-secret User2FAEnabled marker,
 * whose projector sets two_factor_confirmed_at. Returns the plaintext recovery codes
 * for one-time display.
 */
class ConfirmTwoFactorInteractor
{
    public function __construct(
        private readonly TotpVerifier $totp,
        private readonly RecoveryCodeGenerator $recoveryCodes,
    ) {}

    /**
     * @return list<string>
     */
    public function execute(User $user, ConfirmTwoFactorCommand $command): array
    {
        $secret = Cache::get("2fa-setup:{$user->getKey()}");

        if (! is_string($secret)) {
            throw new TwoFactorSetupNotStartedException;
        }

        if (! $this->totp->verify($secret, $command->code)) {
            throw new InvalidTwoFactorCodeException;
        }

        $plaintextCodes = $this->recoveryCodes->generate();

        $user->two_factor_secret = $secret;
        $user->two_factor_recovery_codes = array_map(Hash::make(...), $plaintextCodes);
        $user->save();

        UserAggregate::retrieve($user->getKey())->confirmTwoFactor()->persist();

        Cache::forget("2fa-setup:{$user->getKey()}");

        return $plaintextCodes;
    }
}
