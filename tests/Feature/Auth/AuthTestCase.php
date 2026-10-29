<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;
use App\Models\User;
use RuntimeException;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Laravel\Sanctum\NewAccessToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AssertsAccessLog;
use Illuminate\Support\Facades\Config;
use App\Companies\Entities\CompanyUser;
use Illuminate\Support\Facades\Password;
use App\Foundation\Auth\Totp\TotpVerifier;
use Tests\Concerns\InducesProjectorFailures;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Foundation\Auth\UseCases\TokenRevoker;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\Auth\Events\UserPasswordReset;
use App\Foundation\Auth\Totp\LoginChallengeStore;
use App\Companies\Memberships\Entities\Invitation;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Foundation\Auth\Events\UserPasswordChanged;
use App\Foundation\Auth\UseCases\Google\GoogleIdentity;
use App\Foundation\Auth\UseCases\Google\GoogleIdTokenVerifier;
use App\Foundation\Auth\Exceptions\GoogleTokenInvalidException;
use App\Foundation\Auth\Reactors\PasswordChangedNotificationReactor;

abstract class AuthTestCase extends TestCase
{
    use AssertsAccessLog, InducesProjectorFailures;

    /**
     * Canonical login-ready credentials, shared by the login and logout suites
     * (§2.10). A registered, verified user authenticates with these.
     */
    protected const LOGIN_EMAIL = 'alice@contoso.lk';

    protected const LOGIN_PASSWORD = 'Str0ng-Pass!';

    protected const NEW_PASSWORD = 'New-Str0ng-Pass!';

    protected const GOOGLE_SUBJECT = '109876543210';

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

    /** Bind a Google ID-token verifier double: it reads the given identity, or refuses the token when null. */
    protected function fakeGoogleIdTokenVerifier(?GoogleIdentity $identity): void
    {
        $this->app->instance(GoogleIdTokenVerifier::class, new class($identity) implements GoogleIdTokenVerifier
        {
            public function __construct(private ?GoogleIdentity $identity) {}

            public function verify(string $idToken): GoogleIdentity
            {
                return $this->identity ?? throw new GoogleTokenInvalidException;
            }
        });
    }

    protected function aGoogleIdentityFor(string $email, bool $emailVerified = true): GoogleIdentity
    {
        return new GoogleIdentity(self::GOOGLE_SUBJECT, $email, $emailVerified, 'Alice Perera');
    }

    /**
     * The canonical happy owner-signup body, shared across the owner-signup suites
     * (§2.10). Individual tests override single fields.
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
            'epfNumber' => 'A-123456',
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
            $this->postJson($endpoint, [])->assertUnprocessable();
        }
    }

    protected function assertThrottled(TestResponse $response): void
    {
        $response->assertTooManyRequests();
        $response->assertJsonPath('error_code', 'RATE_LIMIT_EXCEEDED');
        $this->assertGreaterThan(0, (int) $response->json('retry_after'), 'A throttled response must carry retry guidance.');
    }

    protected function assertValidationRejected(TestResponse $response, string $invalidField, string $expectedMessage): void
    {
        $response->assertUnprocessable();
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonValidationErrors([$invalidField => $expectedMessage]);
    }

    protected function assertUnauthenticated(TestResponse $response): void
    {
        $response->assertUnauthorized();
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
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
        return app(LoginChallengeStore::class)->create($user->getKey());
    }

    /**
     * POST a second factor to /2fa/verify against an open challenge. Shared by the verify
     * (UC-1-005) + regenerate (UC-1-137) suites.
     */
    protected function verify(string $challengeId, string $code): TestResponse
    {
        return $this->postJson('/api/v1/auth/2fa/verify', [
            'challengeId' => $challengeId,
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
     * Make $user a member of a company on $plan (the company_user pivot). The device cap
     * is plan-only, so the membership role is irrelevant — a freshly-created role just
     * satisfies the pivot's required role_id. Shared by the login + 2FA-verify device-cap
     * suites (UC-1-002 alt-5a).
     */
    protected function memberOfCompanyOnPlan(User $user, BillingPlan $plan): void
    {
        Company::factory()->onPlan($plan)->create()
            ->users()->attach($user, ['role_id' => Role::factory()->create()->getKey()]);
    }

    protected function memberOfTrialCompany(User $user): void
    {
        Company::factory()->onTrial()->create()
            ->users()->attach($user, ['role_id' => Role::factory()->create()->getKey()]);
    }

    protected function memberOfExpiredTrialCompany(User $user): void
    {
        Company::factory()->trialExpired()->create()
            ->users()->attach($user, ['role_id' => Role::factory()->create()->getKey()]);
    }

    /**
     * Look up a seeded RBAC-catalog role by code — requires seedRbacCatalog() in the
     * calling suite's setUp. Duplicated from MembershipsTestCase (module bases are
     * independent; assertRefusedWith precedent). Shared by the list-companies
     * (UC-1-034) and default-company (UC-1-139) suites.
     */
    protected function seededRole(string $code): Role
    {
        return Role::query()->where('code', $code)->sole();
    }

    /**
     * Make $user a live member of $company under a seeded role — the company_user
     * pivot row the companies list reads. Shared by the list-companies (UC-1-034)
     * and default-company (UC-1-139) suites.
     */
    protected function aMembershipFor(User $user, Company $company, string $roleCode = 'employee'): void
    {
        $company->users()->attach($user, ['role_id' => $this->seededRole($roleCode)->getKey()]);
    }

    /**
     * A membership that was removed (UC-1-024 semantics): the company_user pivot
     * exists but is soft-deleted — the companies list must exclude it. Mirrors
     * MembershipsTestCase::aRemovedMembership. Shared by the list-companies
     * (UC-1-034) and default-company (UC-1-139) suites.
     */
    protected function aRemovedMembershipIn(Company $company, User $user): void
    {
        $this->aMembershipFor($user, $company);

        $this->removeMembership($company, $user);
    }

    /**
     * Soft-delete an EXISTING membership pivot (the removal half of
     * aRemovedMembershipIn) — for arrangements that must act between attach and
     * removal (e.g. set a default company while the membership is still live,
     * UC-1-014 stale-default pin). Shared by the view-profile suite.
     */
    protected function removeMembership(Company $company, User $user): void
    {
        CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->update(['deleted_at' => now()]);
    }

    /**
     * A live-pending invitation row for ($company, $email) with NO company_user
     * pivot — the invited-not-yet-accepted state. Written directly: the projector
     * is the production writer, but this fixture only needs the row's existence
     * (the aRemovedMembership direct-write precedent). Shared by the
     * list-companies (UC-1-034) suite.
     */
    protected function anInvitationRowFor(Company $company, string $email): void
    {
        Invitation::create([
            'company_id' => $company->getKey(),
            'role_id' => $this->seededRole('employee')->getKey(),
            'email' => $email,
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addDays(7),
        ]);
    }

    /**
     * GET the authenticated user's companies list (UC-1-034) — no X-Company-Context,
     * the me/ surface is account-level. Sanctum::actingAs suffices for the pure read
     * (roster precedent). Shared by the list-companies (UC-1-034) and default-company
     * (UC-1-139) suites.
     */
    protected function listCompanies(User $actor): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->getJson('/api/v1/me/companies');
    }

    /**
     * GET the authenticated user's own profile (UC-1-014) — no X-Company-Context,
     * the me/ surface is account-level (listCompanies precedent). Shared by the
     * view-profile (UC-1-014) and update-profile (UC-1-015) suites.
     */
    protected function viewProfile(User $actor): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->getJson('/api/v1/me');
    }

    /**
     * GET /me with no token at all — the unauthenticated probe for the profile
     * suite (UC-1-014 Sc2).
     */
    protected function viewProfileWithoutAuth(): TestResponse
    {
        return $this->getJson('/api/v1/me');
    }

    /**
     * The one logical happy-path assertion (E20): the body is EXACTLY the nine-field
     * {data} envelope built from the user's persisted row. Exactness IS the leak-guard —
     * password, two_factor_secret, two_factor_recovery_codes and remember_token can
     * never serialize without failing this. Callers arrange a verified, 2FA-confirmed
     * user (the hardcoded derivation pins). Shared by the view-profile (UC-1-014) and
     * update-profile (UC-1-015) suites.
     */
    protected function assertExactProfileEnvelope(TestResponse $response, User $user): void
    {
        $user->refresh();

        $response->assertOk();
        $response->assertExactJson(['data' => [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => true,
            'locale' => $user->locale,
            'two_factor_enabled' => true,
            'google_linked' => false,
            'default_company_id' => $user->default_company_id,
            'last_logged_at' => $user->last_logged_at?->toJSON(),
            'created_at' => $user->created_at?->toJSON(),
        ]]);
    }

    /**
     * PUT the default-company preference (UC-1-139) — payload-shaped so the
     * malformed-body variations reuse the same seam. No X-Company-Context:
     * the me/ surface is account-level (listCompanies precedent). Shared by
     * the default-company (UC-1-139) and list-companies (UC-1-034) suites.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function setDefaultCompany(User $actor, array $payload): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->putJson('/api/v1/me/default-company', $payload);
    }

    /**
     * DELETE (clear) the default-company preference (UC-1-139).
     */
    protected function clearDefaultCompany(User $actor): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->deleteJson('/api/v1/me/default-company');
    }

    /**
     * Assert the companies list flags exactly $companyId as the default (every
     * other row false); null asserts NO row is flagged — the B2 read-time
     * fallback oracle. Shared by the default-company (UC-1-139) and
     * list-companies (UC-1-034) suites.
     */
    protected function assertListFlagsDefault(User $user, ?string $companyId): void
    {
        /** @var list<array{id: string, is_default: bool}> $rows */
        $rows = $this->listCompanies($user)->assertOk()->json('data');

        $flagged = collect($rows)->where('is_default', true)->pluck('id')->all();

        $this->assertSame($companyId === null ? [] : [$companyId], $flagged);
    }

    /**
     * Assert a login / 2FA-verify was refused by the device cap (UC-1-002 alt-5a): the
     * §S21 403 DEVICE_LIMIT_REACHED envelope, the canonical message (which pins the
     * Infection-blind bootstrap copy), and that NO new session was minted — the user
     * still holds exactly $expectedTokens. Shared by the login + verify device-cap suites.
     */
    protected function assertDeviceLimitRejected(TestResponse $response, User $user, int $expectedTokens): void
    {
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::DeviceLimitReached);
        $response->assertJsonPath('message', 'Maximum device limit reached. Log out from another device first.');
        $this->assertSame($expectedTokens, $user->tokens()->count(), 'A capped attempt must mint no new session.');
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

    /**
     * Submit a password change to the self-service endpoint (which requires an idempotency key).
     * Shared by the change-password (UC-1-016) and password-changed-notification suites.
     *
     * @param  array<string, string>  $payload
     */
    protected function changePassword(string $token, array $payload, ?string $idempotencyKey = null): TestResponse
    {
        return $this->withToken($token)->putJson('/api/v1/me/password', $payload, [
            'Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid(),
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    protected function linkGoogle(User $actor, array $payload = ['idToken' => 'a-google-id-token']): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->postJson('/api/v1/me/google', $payload, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /**
     * Submit a password reset to the public reset endpoint. Shared by the reset (UC-1-008)
     * and password-changed-notification suites (the latter proves the reset event also fires
     * the confirmation email).
     *
     * @param  array<string, string>  $payload
     */
    protected function resetPassword(array $payload): TestResponse
    {
        return $this->postJson('/api/v1/auth/reset-password', $payload);
    }

    /**
     * Mint a production-faithful single-use password-reset token for $user via the broker's own
     * token repository (the same `password_reset_tokens` row + hashing `Password::broker()->reset`
     * validates) — deterministic, no forgot-flow round-trip or Notification::fake needed. Shared by
     * the reset (UC-1-008) and password-changed-notification suites.
     */
    protected function aResetTokenFor(User $user): string
    {
        // Password::createToken (facade @method) not broker()->createToken: broker() returns the
        // PasswordBroker CONTRACT, which declares only sendResetLink/reset — createToken lives on the
        // concrete impl and is exposed via the facade. Same default broker + password_reset_tokens row.
        return Password::createToken($user);
    }

    /**
     * The new password fully replaced the old one end-to-end: a fresh login with the OLD password is
     * rejected (generic credentials error) and a fresh login with the NEW one succeeds with a session
     * token. Proves the projector wrote the new hash to the read model (users.password is projected).
     * forgetGuards between attempts forces fresh credential resolution, not a cached guard. Shared by
     * the change-password (UC-1-016) and reset-password (UC-1-008) suites.
     */
    protected function assertPasswordReplaced(User $user, string $oldPassword, string $newPassword): void
    {
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $oldPassword])
            ->assertUnauthorized();

        Auth::forgetGuards();
        $newLogin = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $newPassword]);
        $newLogin->assertOk();
        $this->assertNotEmpty($newLogin->json('data.token'), 'The new password must authenticate and issue a session.');
    }

    /**
     * Container-bind a session revoker that fails, so a test can prove the password write it follows
     * shares its transaction. Interactors receive the revoker by constructor injection, so the binding
     * is seen at resolution. Shared by the change-password and reset-password suites.
     */
    protected function bindThrowingTokenRevoker(): void
    {
        $this->app->bind(
            TokenRevoker::class,
            fn (): TokenRevoker => new class extends TokenRevoker
            {
                public function revokeAllForUserExcept(User $user, ?string $exceptTokenId): int
                {
                    throw new RuntimeException('Induced session-revocation failure.');
                }
            });
    }

    /**
     * Container-bind a confirmation reactor whose BOTH handlers fail at delivery time, so a test can
     * prove the committed password write (and what follows it) survives the failure. spatie
     * re-resolves handlers per call, so the binding is seen at the drain. Shared by the
     * change-password (UC-1-016) and reset-password (UC-1-008) suites.
     */
    protected function bindThrowingPasswordChangedReactor(): void
    {
        $this->app->bind(
            PasswordChangedNotificationReactor::class,
            fn (): PasswordChangedNotificationReactor => new class extends PasswordChangedNotificationReactor
            {
                public function onUserPasswordChanged(UserPasswordChanged $event): void
                {
                    throw new RuntimeException('Induced delivery-time reactor failure.');
                }

                public function onUserPasswordReset(UserPasswordReset $event): void
                {
                    throw new RuntimeException('Induced delivery-time reactor failure.');
                }
            });
    }
}
