<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
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
