<?php

namespace App\Foundation\Auth\Totp;

/**
 * The transient result of starting 2FA enrollment (UC-1-006): the freshly generated
 * TOTP secret and the otpauth:// URI the client renders as a QR code. Nothing is
 * persisted yet — the secret is staged in a short-TTL cache until /2fa/confirm.
 */
class TwoFactorSetup
{
    public function __construct(
        public readonly string $secret,
        public readonly string $otpauthUri,
    ) {}
}
