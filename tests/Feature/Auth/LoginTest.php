<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Arr;
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
        $this->exhaustAuthLimit(10);

        // When another login is attempted
        $response = $this->postJson('/api/v1/auth/login', []);

        // Then it is throttled with retry guidance
        $this->assertThrottled($response);
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

    protected function assertLoginSucceeded(TestResponse $response, User $user): void
    {
        $response->assertOk();
        $response->assertJsonStructure(['data' => ['token', 'expiresAt', 'user' => ['id', 'name', 'email']]]);
        $response->assertJsonPath('data.user.email', $user->email);
    }

    protected function assertCredentialsRejected(TestResponse $response): void
    {
        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $response->assertJsonPath('message', 'Invalid credentials.');
    }

    protected function assertValidationRejected(TestResponse $response, string $invalidField): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonValidationErrors($invalidField);
    }

    protected function exhaustAuthLimit(int $allowed): void
    {
        // Each request up to the limit must pass the throttle (and only then be
        // rejected by validation) — this pins the allowance at exactly $allowed, so
        // an off-by-one on the limit throttles one of these instead. Relies on the
        // test array cache resetting the limiter per test method (CACHE_STORE=array).
        for ($i = 0; $i < $allowed; $i++) {
            $this->postJson('/api/v1/auth/login', [])->assertStatus(422);
        }
    }

    protected function assertThrottled(TestResponse $response): void
    {
        $response->assertStatus(429);
        $response->assertJsonPath('error_code', 'RATE_LIMIT_EXCEEDED');
        $this->assertGreaterThan(0, (int) $response->json('retry_after'), 'A throttled response must carry retry guidance.');
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
