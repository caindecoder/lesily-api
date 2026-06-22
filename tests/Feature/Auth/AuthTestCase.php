<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use App\Foundation\Auth\Totp\TotpVerifier;

abstract class AuthTestCase extends TestCase
{
    /**
     * Canonical login-ready credentials, shared by the login and logout suites
     * (§2.10). A registered, verified user authenticates with these.
     */
    protected const LOGIN_EMAIL = 'alice@contoso.lk';

    protected const LOGIN_PASSWORD = 'Str0ng-Pass!';

    /**
     * Arrange a registered, login-ready user. The raw password is hashed by the
     * model's `hashed` cast on save, so the user authenticates with $password.
     * Shared by the login (UC-1-002) and logout (UC-1-004) suites.
     */
    protected function aRegisteredUser(
        string $email = self::LOGIN_EMAIL,
        string $password = self::LOGIN_PASSWORD,
    ): User {
        return User::factory()->withEmail($email)->withPassword($password)->create();
    }

    /**
     * A registered, login-ready user with 2FA already enabled. Shared by the 2FA
     * login-challenge (UC-1-002 alt-4a) and verify (UC-1-005) suites. Pass
     * $recoveryCodes to seed known plaintext codes for the recovery-path tests;
     * null generates 10 random codes.
     *
     * @param  list<string>|null  $recoveryCodes
     */
    protected function aTwoFactorUser(?array $recoveryCodes = null): User
    {
        return User::factory()
            ->withEmail(self::LOGIN_EMAIL)
            ->withPassword(self::LOGIN_PASSWORD)
            ->withTwoFactor($recoveryCodes)
            ->create();
    }

    /**
     * Bind a deterministic TotpVerifier double — the only mocked boundary (E23). It
     * stages a runtime-generated secret (no literal — SonarCloud) and lets the test
     * decide whether a submitted code verifies. Shared by the 2FA enrollment
     * (UC-1-006) and verify (UC-1-005) suites.
     */
    protected function fakeTotpVerifier(bool $verifies = true): void
    {
        $this->app->instance(TotpVerifier::class, new class($verifies) implements TotpVerifier
        {
            public function __construct(private bool $verifies) {}

            public function generateSecret(): string
            {
                return 'STAGED'.Str::upper(Str::random(10));
            }

            public function provisioningUri(string $secret, string $accountName): string
            {
                return "otpauth://totp/Lesily:{$accountName}?secret={$secret}&issuer=Lesily";
            }

            public function verify(string $secret, string $code): bool
            {
                return $this->verifies;
            }
        });
    }

    /**
     * The canonical happy owner-signup body, shared by the register happy-path
     * and sad-path suites (§2.10). Individual tests override single fields.
     *
     * @return array<string, mixed>
     */
    protected function validRegistrationPayload(): array
    {
        return [
            'name' => 'Nimal Perera',
            'email' => 'nimal@contoso.lk',
            'password' => 'S3cure-Pass!',
            'acceptedTos' => true,
            'companyName' => 'Contoso',
            'brNumber' => 'PV-12345678',
            'epfNumber' => 'A-1234567',
            'country' => 'LK',
            'emergencyContactName' => 'Kamala Perera',
            'emergencyContactEmail' => 'kamala@contoso.lk',
            'emergencyContactPhone' => '+94771234567',
            'emergencyContactRelationship' => 'spouse',
        ];
    }

    /**
     * Exhaust the shared auth-tier per-IP limit against $endpoint. Each request up
     * to the limit must pass the throttle (and only then be rejected by validation),
     * pinning the allowance at exactly $allowed — an off-by-one on the limit
     * throttles one of these instead. Relies on the array cache resetting the
     * limiter per test method (CACHE_STORE=array). Shared by the auth suites (§2.10).
     */
    protected function exhaustAuthLimit(int $allowed, string $endpoint): void
    {
        for ($i = 0; $i < $allowed; $i++) {
            $this->postJson($endpoint, [])->assertStatus(422);
        }
    }

    protected function assertThrottled(TestResponse $response): void
    {
        $response->assertStatus(429);
        $response->assertJsonPath('error_code', 'RATE_LIMIT_EXCEEDED');
        $this->assertGreaterThan(0, (int) $response->json('retry_after'), 'A throttled response must carry retry guidance.');
    }

    protected function assertValidationRejected(TestResponse $response, string $invalidField): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonValidationErrors($invalidField);
    }
}
