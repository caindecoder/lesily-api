<?php

namespace Tests\Feature\Auth\Password;

use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Requesting a password-reset link (UC-1-008, forgot portion). A guest submits an email; the
 * response is an enumeration-safe 204 whether or not the email is registered, the request is
 * rate-limited 3/15min per email, and a registered email is emailed a single-use reset link.
 */
class ForgotPasswordTest extends AuthTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Error-path render parity (amendment M): APP_DEBUG=true masks the prod render path, so
        // the 422/429 rejections below must render their mapped envelopes the way CI/prod do.
        config(['app.debug' => false]);
    }

    #[Test]
    #[DataProvider('invalidEmailProvider')]
    public function when_the_email_is_invalid_then_the_request_is_rejected(array $payload): void
    {
        // Given an invalid forgot-password payload
        // When it is submitted
        $response = $this->forgotPassword($payload);

        // Then it is rejected as a validation error
        $this->assertValidationRejected($response, 'email');
    }

    #[Test]
    public function when_too_many_requests_are_made_then_they_are_throttled(): void
    {
        // Given a valid email (registration is irrelevant — the throttle runs before the broker)
        $email = 'guest@contoso.lk';

        // When the window's allowance of requests is used up
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->forgotPassword(['email' => $email])->assertNoContent();
        }

        // Then the next request is throttled
        $this->assertThrottled($this->forgotPassword(['email' => $email]));
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function invalidEmailProvider(): array
    {
        return [
            'missing email' => [[]],
            'empty email' => [['email' => '']],
            'malformed email' => [['email' => 'not-an-email']],
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function forgotPassword(array $payload): TestResponse
    {
        return $this->postJson('/api/v1/auth/forgot-password', $payload);
    }
}
