<?php

namespace Tests\Feature\Auth;

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

    protected function assertCredentialsRejected(TestResponse $response): void
    {
        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $response->assertJsonPath('message', 'Invalid credentials.');
    }
}
