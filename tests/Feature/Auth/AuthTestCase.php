<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Config;
use App\Foundation\Auth\Totp\TotpVerifier;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\Auth\Totp\LoginChallengeStore;

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

    protected function assertUnauthenticated(TestResponse $response): void
    {
        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    /**
     * Assert a request was refused with the §S21 envelope for $code (status + error_code).
     * Shared by the 2FA verify / disable / regenerate suites (§2.10).
     */
    protected function assertRefusedWith(TestResponse $response, ApiErrorCode $code): void
    {
        $response->assertStatus($code->status());
        $response->assertJsonPath('error_code', $code->value);
    }

    /**
     * Assert the user's single issued session token is named for $expectedName — the
     * device-name resolution oracle (sessions slice). `sole()` doubles as the
     * exactly-one-token-minted guard. Shared by the login / 2FA-verify / register suites.
     */
    protected function assertTokenNamed(User $user, string $expectedName): void
    {
        $this->assertSame($expectedName, $user->tokens()->sole()->name);
    }

    /**
     * Assert the user's `last_logged_at` was stamped to the current (frozen) time on a
     * successful login. Relies on a frozen clock in the calling test. Shared by the
     * login / 2FA-verify / register suites (sessions slice).
     */
    protected function assertLastLoggedAtStamped(User $user): void
    {
        $this->assertSame(now()->toDateTimeString(), $user->refresh()->last_logged_at?->toDateTimeString());
    }

    /**
     * Open a 2FA login challenge for $user and return its opaque id — the bridge a
     * /2fa/verify call consumes. Shared by the verify (UC-1-005) + regenerate (UC-1-137)
     * suites (the latter proves a regenerated code rotation end-to-end at verify).
     */
    protected function openChallenge(User $user): string
    {
        return app(LoginChallengeStore::class)->create($user->id);
    }

    /**
     * POST a second factor to /2fa/verify against an open challenge. Shared by the verify
     * (UC-1-005) + regenerate (UC-1-137) suites.
     */
    protected function verify(string $challengeId, string $code): TestResponse
    {
        return $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ]);
    }

    /**
     * Mint a production-faithful active session for $user: an explicit ~90-day expiry
     * exactly as SessionIssuer stamps it (createToken alone leaves expires_at null, which
     * no real login produces). Returns the NewAccessToken so callers get both the plain
     * text (to authenticate) and the token id (to target / assert on). Shared by the
     * view-sessions (UC-1-010) and revoke-session suites.
     */
    protected function aSessionFor(User $user, string $name = 'web'): NewAccessToken
    {
        return $user->createToken($name, ['*'], now()->addMinutes(Config::integer('sanctum.expiration')));
    }

    /**
     * Mint a session whose explicit expiry is already in the past — a dead session whose row
     * lingers until pruned. Shared by the view-sessions (expired-exclusion) and revoke-session
     * (revoke-an-expired-row) suites.
     */
    protected function anExpiredSessionFor(User $user, string $name = 'old-laptop'): NewAccessToken
    {
        return $user->createToken($name, ['*'], now()->subDay());
    }

    /**
     * Probe a protected route with $token so it reflects the token's CURRENT state, like a
     * fresh client request. Within one test the container reuses the RequestGuard, which
     * caches the user resolved by a prior request, so a just-revoked token false-passes
     * unless guards are forgotten first (amendment P / feedback_sanctum-guard-cache-test).
     * Shared by the logout (UC-1-004) and revoke-session suites.
     */
    protected function probeWith(string $token): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->getJson('/api/_test/authenticated-probe');
    }
}
