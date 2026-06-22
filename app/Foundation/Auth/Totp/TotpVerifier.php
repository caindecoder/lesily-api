<?php

namespace App\Foundation\Auth\Totp;

/**
 * The TOTP boundary (DIP). Wraps the time-based one-time-password primitive so the
 * use cases depend on this contract, not on pragmarx/google2fa directly — and so
 * feature tests can substitute a deterministic double (the only mocked seam, E23).
 */
interface TotpVerifier
{
    /** Generate a fresh base32 shared secret. */
    public function generateSecret(): string;

    /** Build the otpauth:// URI the client renders as a QR code. */
    public function provisioningUri(string $secret, string $accountName): string;

    /** Check a submitted code against the secret (with a small time-skew window). */
    public function verify(string $secret, string $code): bool;
}
