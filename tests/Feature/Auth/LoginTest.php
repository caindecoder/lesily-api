<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Auth\Totp\LoginChallengeStore;

class LoginTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Frozen clock so the 90-day token expiry is assertable to the second.
        $this->travelTo('2026-06-21 00:00:00');
    }

    // Sad — a correct email with the wrong password is rejected with the generic
    // credentials error (no hint that the email exists).
    #[Test]
    public function when_the_password_is_wrong_then_login_is_rejected_with_a_generic_credentials_error(): void
    {
        // Given a registered user
        $this->aRegisteredUser();

        // When they submit the right email but the wrong password
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => 'not-the-password',
        ]);

        // Then the attempt is rejected with the generic credentials error
        $this->assertCredentialsRejected($response);
    }

    // Sad — an unknown email is rejected identically to a wrong password, so the
    // response never discloses whether an email is registered (anti-enumeration).
    #[Test]
    public function when_the_email_is_unknown_then_login_is_rejected_with_the_same_generic_error(): void
    {
        // Given a registered user, so a wrong-password rejection exists to compare against
        $this->aRegisteredUser();

        // When an unknown email and the known email with a wrong password are each tried
        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'ghost@contoso.lk',
            'password' => self::LOGIN_PASSWORD,
        ]);
        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => 'not-the-password',
        ]);

        // Then the unknown-email response is rejected identically to the wrong-password one
        $this->assertCredentialsRejected($unknownEmail);
        $this->assertSameRejection($unknownEmail, $wrongPassword);
    }

    // Sad — a missing or malformed credential field is rejected by shape validation
    // before the credential check runs (the login seam stays lenient otherwise: no
    // password policy is surfaced here — a too-short password is a 401, not a 422).
    #[Test]
    #[DataProvider('invalidCredentialPayloadProvider')]
    public function when_a_required_credential_field_is_missing_or_malformed_then_the_request_is_rejected_as_invalid(array $override, string $invalidField, string $expectedMessage): void
    {
        // Given an otherwise-valid login with one missing or malformed field
        $payload = [
            'email' => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
            ...$override,
        ];

        // When the guest submits it
        $response = $this->postJson('/api/v1/auth/login', $payload);

        // Then login is rejected by validation and the offending field is reported
        $this->assertValidationRejected($response, $invalidField, $expectedMessage);
    }

    /**
     * Each row blanks or corrupts one field of an otherwise-valid login and pins the
     * rule's verbatim message.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidCredentialPayloadProvider(): array
    {
        return [
            'blank email' => [['email' => ''], 'email', 'The email field is required.'],
            'blank password' => [['password' => ''], 'password', 'The password field is required.'],
            'malformed email' => [['email' => 'not-an-email'], 'email', 'The email field must be a valid email address.'],
        ];
    }

    // Sad — the public login endpoint runs under the auth-tier per-IP limit
    // (10/min) so credential stuffing against one IP is throttled.
    #[Test]
    public function when_login_attempts_from_one_ip_exceed_the_limit_then_further_attempts_are_throttled(): void
    {
        // Given the per-IP auth quota for the minute is exhausted
        $this->exhaustAuthLimit(10, '/api/v1/auth/login');

        // When another login is attempted
        $response = $this->postJson('/api/v1/auth/login', []);

        // Then it is throttled with retry guidance
        $this->assertThrottled($response);
    }

    // Sad — a Pro/Enterprise account already at its three active-device limit is refused
    // a fourth session at login (UC-1-002 alt-5a); the cap is enforced before the mint.
    #[Test]
    #[DataProvider('provideDeviceCappedPlans')]
    public function when_a_capped_account_at_the_limit_logs_in_then_it_is_rejected(BillingPlan $plan): void
    {
        // Given a user on a device-capped plan already holding three active sessions
        $user = $this->aRegisteredUser();
        $this->memberOfCompanyOnPlan($user, $plan);
        $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');
        $this->aSessionFor($user, 'tablet');

        // When they log in from a fourth device
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);

        // Then it is rejected, no fourth session is minted, and "last seen" is not bumped
        $this->assertDeviceLimitRejected($response, $user, 3);
        $this->assertNull($user->refresh()->last_logged_at, 'A capped login must not stamp last_logged_at.');
    }

    /**
     * The two device-capped tiers — Pro and Enterprise both enforce the 3-device
     * concurrent limit (S14); Free and Starter are uncapped.
     *
     * @return array<string, array{BillingPlan}>
     */
    public static function provideDeviceCappedPlans(): array
    {
        return [
            'pro' => [BillingPlan::Pro],
            'enterprise' => [BillingPlan::Enterprise],
        ];
    }

    // Edge — the issued token is a real credential: it authenticates a Sanctum-guarded
    // route as the logged-in user (the token round-trip truth oracle).
    #[Test]
    public function when_a_valid_login_succeeds_then_the_issued_token_authenticates_a_protected_route(): void
    {
        // Given a registered user who logs in and receives a token
        $user = $this->aRegisteredUser();
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ])->json('data.token');

        // When the token is presented to a Sanctum-guarded route
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/_test/authenticated-probe');

        // Then it authenticates as that user
        $response->assertOk();
        $response->assertJsonPath('user_id', $user->getKey());
    }

    // Edge — the issued token carries the locked 90-day expiry (api-conventions §7 /
    // sanctum config), surfaced to the client as `expiresAt`.
    #[Test]
    public function when_a_token_is_issued_then_it_carries_the_ninety_day_expiry(): void
    {
        // Given a registered user (the clock is frozen in setUp)
        $this->aRegisteredUser();

        // When they log in
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);

        // Then the returned expiry is exactly 90 days out
        $expiresAt = $response->json('data.expiresAt');
        $this->assertNotNull($expiresAt);
        $this->assertSame(
            now()->addDays(90)->toDateTimeString(),
            Carbon::parse($expiresAt)->toDateTimeString(),
        );
    }

    // Edge — the issued token is named for the device so the Active Sessions list can
    // identify it: an explicit device name wins, else the parsed User-Agent, else a
    // safe "Unknown device" floor (sessions slice).
    #[Test]
    #[DataProvider('deviceNameResolutionProvider')]
    public function when_login_issues_a_token_then_it_is_named_by_the_device_resolution_rules(?string $suppliedDeviceName, string $userAgent, string $expectedTokenName): void
    {
        // Given a registered user
        $user = $this->aRegisteredUser();
        $payload = ['email' => self::LOGIN_EMAIL, 'password' => self::LOGIN_PASSWORD];
        if ($suppliedDeviceName !== null) {
            $payload['deviceName'] = $suppliedDeviceName;
        }

        // When they log in from a device — supplying a name, or relying on the User-Agent
        $this->withHeader('User-Agent', $userAgent)
            ->postJson('/api/v1/auth/login', $payload)
            ->assertOk();

        // Then the issued token carries the resolved device name
        $this->assertTokenNamed($user, $expectedTokenName);
    }

    /**
     * The device-name resolution rules: an explicit `deviceName` wins; absent it, the
     * parsed User-Agent names the token; a blank User-Agent falls back to "Unknown device"
     * (the degenerate floor — a token must always carry a meaningful name).
     *
     * @return array<string, array{0: ?string, 1: string, 2: string}>
     */
    public static function deviceNameResolutionProvider(): array
    {
        return [
            'blank user agent falls back to unknown device' => [null, '', 'Unknown device'],
            'user agent names the device when none supplied' => [null, 'Mozilla/5.0 (TestAgent)', 'Mozilla/5.0 (TestAgent)'],
            'explicit device name wins' => ['Alice iPhone', 'Mozilla/5.0 (TestAgent)', 'Alice iPhone'],
        ];
    }

    // Edge — a malformed (non-string) deviceName is tolerated, not a 500: the resolver
    // runs before validation, so an array body must be treated as "not supplied" and
    // fall back to the User-Agent rather than reaching the typed sink and crashing.
    #[Test]
    public function when_the_supplied_device_name_is_not_a_string_then_it_is_ignored_in_favour_of_the_user_agent(): void
    {
        // Given a registered user
        $user = $this->aRegisteredUser();

        // When they log in with a non-string deviceName (an array) from a known device
        $this->withHeader('User-Agent', 'Mozilla/5.0 (TestAgent)')
            ->postJson('/api/v1/auth/login', [
                'email' => self::LOGIN_EMAIL,
                'password' => self::LOGIN_PASSWORD,
                'deviceName' => ['not', 'a', 'string'],
            ])->assertOk();

        // Then the malformed name is ignored and the token is named for the User-Agent
        $this->assertTokenNamed($user, 'Mozilla/5.0 (TestAgent)');
    }

    // Edge — a successful (non-2FA) login stamps the user's "last seen" timestamp
    // (sessions slice R10); the stamp lives in the interactor, not the mint service.
    #[Test]
    public function when_a_non_two_factor_login_succeeds_then_last_logged_at_is_stamped(): void
    {
        // Given a registered user who has never logged in (the clock is frozen in setUp)
        $user = $this->aRegisteredUser();

        // When they log in
        $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ])->assertOk();

        // Then their last_logged_at is stamped to now
        $this->assertLastLoggedAtStamped($user);
    }

    // Edge — a member removed from their device-capped company (soft-deleted pivot) is
    // no longer capped: the trashed pivot must not count toward the per-account device
    // limit. UC-1-024 is the first path that soft-deletes a pivot, so it activates this
    // latent leak in User::companies() — the R7 sweep must close it (DeviceLimitGuard).
    #[Test]
    public function when_a_capped_membership_is_removed_then_the_account_is_no_longer_device_limited(): void
    {
        // Given a user removed from their only Pro company but still holding three sessions
        $user = $this->aRegisteredUser();
        $this->memberOfCompanyOnPlan($user, BillingPlan::Pro);
        CompanyUser::query()->where('user_id', $user->getKey())->update(['deleted_at' => now()]);
        $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');
        $this->aSessionFor($user, 'tablet');

        // When they log in from a fourth device
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);

        // Then the removed membership no longer caps them — the login succeeds
        $response->assertOk();
    }

    // Edge — Free and Starter accounts are uncapped: a fourth concurrent device logs in
    // normally; the cap applies only to Pro+ members (UC-1-002 alt-5a / S14).
    #[Test]
    #[DataProvider('provideUncappedPlans')]
    public function when_an_uncapped_plan_exceeds_three_devices_then_login_is_allowed(BillingPlan $plan): void
    {
        // Given a user on an uncapped plan already holding three active sessions
        $user = $this->aRegisteredUser();
        $this->memberOfCompanyOnPlan($user, $plan);
        $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');
        $this->aSessionFor($user, 'tablet');

        // When they log in from a fourth device
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);

        // Then login still succeeds and the fourth session is issued (uncapped)
        $this->assertSessionIssued($response, $user, 4);
    }

    /**
     * The uncapped tiers — Free and Starter carry no concurrent-device limit (S14).
     *
     * @return array<string, array{BillingPlan}>
     */
    public static function provideUncappedPlans(): array
    {
        return [
            'free' => [BillingPlan::Free],
            'starter' => [BillingPlan::Starter],
        ];
    }

    // Edge — only active sessions count toward the cap: a Pro member with two live
    // sessions plus one expired (unpruned) row is under the limit, so a third device
    // logs in — the expired row must not be counted (UC-1-002 alt-5a).
    #[Test]
    public function when_an_expired_token_does_not_count_toward_the_limit(): void
    {
        // Given a Pro member with two active sessions and one expired (unpruned) row
        $user = $this->aRegisteredUser();
        $this->memberOfCompanyOnPlan($user, BillingPlan::Pro);
        $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');
        $this->anExpiredSessionFor($user, 'old-laptop');

        // When they log in from a third active device
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);

        // Then login succeeds — the expired row was not counted (else they'd be at the cap)
        $this->assertSessionIssued($response, $user, 4);
    }

    // Edge — a member of a planless (trial) company is uncapped: an absent plan enforces no
    // device cap, so a fourth concurrent device logs in (UC-1-002 alt-5a — null plan → uncapped).
    #[Test]
    public function when_a_planless_company_member_exceeds_three_devices_then_login_is_allowed(): void
    {
        // Given a member of a company with no plan chosen yet (trial), holding three active sessions
        $user = $this->aRegisteredUser();
        $this->memberOfPlanlessCompany($user);
        $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');
        $this->aSessionFor($user, 'tablet');

        // When they log in from a fourth device
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);

        // Then login still succeeds — a null plan does not enforce the cap
        $this->assertSessionIssued($response, $user, 4);
    }

    // Edge — the cap is per-account across memberships: a user in BOTH an uncapped (Free) and
    // a capped (Pro) company is capped — one capped membership is enough (UC-1-002 alt-5a).
    #[Test]
    public function when_a_member_of_a_capped_and_an_uncapped_company_is_at_the_limit_then_login_is_rejected(): void
    {
        // Given a user on three active devices, a member of a Free company AND a Pro company
        $user = $this->aRegisteredUser();
        $this->memberOfCompanyOnPlan($user, BillingPlan::Free);
        $this->memberOfCompanyOnPlan($user, BillingPlan::Pro);
        $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');
        $this->aSessionFor($user, 'tablet');

        // When they log in from a fourth device
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);

        // Then the Pro membership caps them — one capped membership is sufficient
        $this->assertDeviceLimitRejected($response, $user, 3);
    }

    // Edge — a 2FA-enrolled capped user is NOT rejected at login: the cap is deferred to the
    // verify mint site, so login still returns the 202 challenge (the guard sits after the
    // 2FA gate, which mints no token) — UC-1-002 alt-5a / alt-4a.
    #[Test]
    public function when_a_capped_two_factor_user_logs_in_then_the_challenge_is_issued_not_the_cap_error(): void
    {
        // Given a 2FA-enrolled Pro member already at three active devices
        $user = $this->aTwoFactorUser();
        $this->memberOfCompanyOnPlan($user, BillingPlan::Pro);
        $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');
        $this->aSessionFor($user, 'tablet');

        // When they log in with valid credentials
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);

        // Then a 2FA challenge is issued (202), not the cap error — the cap is enforced at verify
        $response->assertAccepted();
        $this->assertNotEmpty($response->json('data.challenge_id'), 'A 2FA login must return a challenge, not the device-cap error.');
        $this->assertSame(3, $user->tokens()->count(), 'No token is minted at the challenge step.');
    }

    // Edge — a 2FA-enrolled user gets a challenge instead of a token: the challenge
    // resolves to that user and advertises its real expiry (the clock is frozen in setUp).
    #[Test]
    public function when_a_two_factor_user_submits_valid_credentials_then_a_challenge_is_issued_without_a_token(): void
    {
        // Given a registered user with 2FA enabled
        $user = $this->aTwoFactorUser();

        // When they submit valid credentials
        $response = $this->login();

        // Then a 2FA challenge is issued instead of a token, and it resolves to that user
        $this->assertChallengeIssued($response, $user);
    }

    // Happy — valid credentials return a Sanctum token and the user record.
    #[Test]
    public function when_valid_credentials_are_submitted_then_the_token_and_user_are_returned(): void
    {
        // Given a registered user
        $user = $this->aRegisteredUser();

        // When they submit valid credentials
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);

        // Then a token and their user record are returned
        $this->assertLoginSucceeded($response, $user);
    }

    // Happy — an unverified user can still log in: email verification is NOT enforced
    // this slice (slice-auth-login C4). Guards against a verification gate creeping in.
    #[Test]
    public function when_an_unverified_user_submits_valid_credentials_then_login_still_succeeds(): void
    {
        // Given a registered but unverified user
        $user = User::factory()
            ->withEmail(self::LOGIN_EMAIL)
            ->withPassword(self::LOGIN_PASSWORD)
            ->unverified()
            ->create();

        // When they submit valid credentials
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);

        // Then login still succeeds (no email-verification gate)
        $this->assertLoginSucceeded($response, $user);
    }

    // Happy — the cap permits a legitimate login: a Pro member below the limit (two active
    // devices) logs in on a third device and the session is issued (UC-1-002 alt-5a).
    #[Test]
    public function when_a_pro_member_below_the_device_limit_logs_in_then_a_session_is_issued(): void
    {
        // Given a Pro member with two active sessions (under the cap)
        $user = $this->aRegisteredUser();
        $this->memberOfCompanyOnPlan($user, BillingPlan::Pro);
        $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');

        // When they log in from a third device
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);

        // Then the cap allows it and the third session is issued
        $this->assertSessionIssued($response, $user, 3);
    }

    protected function login(): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);
    }

    protected function assertChallengeIssued(TestResponse $response, User $user): void
    {
        $response->assertAccepted();
        $response->assertJsonMissingPath('data.token');
        $this->assertSame(0, $user->tokens()->count(), 'No Sanctum token may be minted until 2FA verification.');
        $challengeId = (string) $response->json('data.challenge_id');
        $this->assertTrue(
            now()->addMinutes(LoginChallengeStore::TTL_MINUTES)->equalTo($response->json('data.expires_at')),
            'The challenge must advertise its real 5-minute expiry.',
        );
        $this->assertSame($user->getKey(), app(LoginChallengeStore::class)->find($challengeId)?->userId);
    }

    protected function assertLoginSucceeded(TestResponse $response, User $user): void
    {
        $response->assertOk();
        $response->assertJsonStructure(['data' => ['token', 'expiresAt', 'user' => ['id', 'name', 'email']]]);
        $response->assertJsonPath('data.user.email', $user->email);
        // The user block whitelists {id,name,email}; pin that the hash never leaks.
        $response->assertJsonMissingPath('data.user.password');
    }

    protected function assertSessionIssued(TestResponse $response, User $user, int $expectedTokens): void
    {
        $response->assertOk();
        $this->assertNotEmpty($response->json('data.token'), 'A session token must be issued.');
        $this->assertSame($expectedTokens, $user->tokens()->count(), 'The new session must be minted.');
    }

    protected function assertCredentialsRejected(TestResponse $response): void
    {
        $response->assertUnauthorized();
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $response->assertJsonPath('message', 'Invalid credentials.');
    }

    protected function assertSameRejection(TestResponse $unknownEmail, TestResponse $wrongPassword): void
    {
        // The per-request correlation_id legitimately differs and reveals nothing;
        // every other field must match so the two failures are indistinguishable.
        $this->assertSame($unknownEmail->status(), $wrongPassword->status());
        $this->assertSame(
            Arr::except($unknownEmail->json(), 'correlation_id'),
            Arr::except($wrongPassword->json(), 'correlation_id'),
        );
    }
}
