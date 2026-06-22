<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use App\Foundation\Auth\Totp\TotpVerifier;
use App\Foundation\Auth\Totp\TwoFactorSetup;
use App\Foundation\Auth\Exceptions\TwoFactorAlreadyEnabledException;

/**
 * Starts 2FA enrollment (UC-1-006 steps 2-3): generate a TOTP secret, stage it in a
 * short-TTL cache (nothing touches the users row until /2fa/confirm verifies a code —
 * the Minimal Guarantee), and return the secret + provisioning URI for the QR.
 */
class EnableTwoFactorInteractor
{
    public function __construct(private readonly TotpVerifier $totp) {}

    public function execute(User $user): TwoFactorSetup
    {
        if ($user->two_factor_confirmed_at !== null) {
            throw new TwoFactorAlreadyEnabledException;
        }

        $secret = $this->totp->generateSecret();

        Cache::put("2fa-setup:{$user->getKey()}", $secret, now()->addMinutes(10));

        return new TwoFactorSetup(
            secret: $secret,
            otpauthUri: $this->totp->provisioningUri($secret, $user->email),
        );
    }
}
