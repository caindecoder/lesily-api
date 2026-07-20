<?php

namespace Tests\Feature\Auth\TwoFactor;

use Tests\TestCase;
use PragmaRX\Google2FA\Google2FA;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Auth\Totp\Google2faTotpVerifier;

/**
 * Exercises the REAL pragmarx/google2fa adapter end-to-end (the feature suite stubs
 * the TotpVerifier boundary, so this is the only place the actual delegation +
 * verifyKey()/getQRCodeUrl() mapping runs — an inverted comparison or wrong argument
 * order would otherwise go uncaught).
 *
 * @testclass-carveout: adapter — certification of the real pragmarx/google2fa
 * delegation; every feature walk stubs the TotpVerifier boundary
 * (AuthTestCase::fakeTotpVerifier), so this is the only place it runs (§2.14 Rule B).
 */
class Google2faTotpVerifierTest extends TestCase
{
    #[Test]
    public function when_a_structurally_impossible_code_is_submitted_then_it_does_not_verify(): void
    {
        // Given the real verifier and a freshly generated secret
        $verifier = new Google2faTotpVerifier(new Google2FA);
        $secret = $verifier->generateSecret();

        // When a structurally-impossible code is checked
        $verified = $verifier->verify($secret, 'not-a-totp-code');

        // Then it does not verify
        $this->assertFalse($verified);
    }

    #[Test]
    public function when_the_current_authenticator_code_is_submitted_then_it_verifies(): void
    {
        // Given the real verifier and a freshly generated secret
        $google2fa = new Google2FA;
        $verifier = new Google2faTotpVerifier($google2fa);
        $secret = $verifier->generateSecret();

        // When the authenticator's current code is checked
        $currentCode = $google2fa->getCurrentOtp($secret);

        // Then it verifies
        $this->assertTrue($verifier->verify($secret, $currentCode));
    }

    #[Test]
    public function when_a_provisioning_uri_is_built_then_it_is_a_well_formed_otpauth_uri(): void
    {
        // Given the real verifier and a secret
        $verifier = new Google2faTotpVerifier(new Google2FA);
        $secret = $verifier->generateSecret();

        // When the provisioning URI is built for an account
        $uri = $verifier->provisioningUri($secret, 'alice@contoso.lk');

        // Then it is an otpauth:// TOTP URI carrying the secret and the issuer
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString($secret, $uri);
        $this->assertStringContainsString(rawurlencode((string) config('app.name')), $uri);
    }
}
