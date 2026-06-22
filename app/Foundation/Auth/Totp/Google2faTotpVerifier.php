<?php

namespace App\Foundation\Auth\Totp;

use PragmaRX\Google2FA\Google2FA;
use Illuminate\Support\Facades\Config;

/**
 * pragmarx/google2fa adapter for the TotpVerifier boundary — a thin delegate +
 * format mapper, no policy of its own (slice-2fa /simplicity-check watch: if this
 * grows branching logic, re-evaluate the boundary). The QR is rendered client-side
 * from the otpauth:// URI, so there is no server-side image dependency.
 */
class Google2faTotpVerifier implements TotpVerifier
{
    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function provisioningUri(string $secret, string $accountName): string
    {
        return $this->google2fa->getQRCodeUrl(Config::string('app.name'), $accountName, $secret);
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code) !== false;
    }
}
