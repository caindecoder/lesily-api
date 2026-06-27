<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LoginTest extends AuthTestCase
{
    use RefreshDatabase;

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
    public function when_a_required_credential_field_is_missing_or_malformed_then_the_request_is_rejected_as_invalid(array $override, string $invalidField): void
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
        $this->assertValidationRejected($response, $invalidField);
    }

    /**
     * Each row blanks or corrupts one field of an otherwise-valid login. The blank
     * rows are regression guards over the `required` rules; the malformed-email row
     * drives the `email` format rule (a non-email string must not reach the lookup).
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidCredentialPayloadProvider(): array
    {
        return [
            'blank email' => [['email' => ''], 'email'],
            'blank password' => [['password' => ''], 'password'],
            'malformed email' => [['email' => 'not-an-email'], 'email'],
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
        $response->assertJsonPath('user_id', $user->id);
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

    protected function assertLoginSucceeded(TestResponse $response, User $user): void
    {
        $response->assertOk();
        $response->assertJsonStructure(['data' => ['token', 'expiresAt', 'user' => ['id', 'name', 'email']]]);
        $response->assertJsonPath('data.user.email', $user->email);
        // The user block whitelists {id,name,email}; pin that the hash never leaks.
        $response->assertJsonMissingPath('data.user.password');
    }

    protected function assertCredentialsRejected(TestResponse $response): void
    {
        $response->assertStatus(401);
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
