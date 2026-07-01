<?php

namespace Tests\Feature\Auth\Password;

use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Resetting a forgotten password via the emailed link (UC-1-008, reset portion). A guest submits a
 * single-use token + their email + a new password; a valid token replaces the password (event-sourced),
 * invalidates ALL of the user's sessions and confirms by email, while an invalid / expired / used token
 * is collapsed to a single 422 RESET_LINK_INVALID (the broker deletes a consumed token, so expired vs
 * used are indistinguishable — collapsing also avoids leaking token state).
 */
class ResetPasswordTest extends AuthTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Error-path render parity (amendment M): APP_DEBUG=true (the local default) masks the prod
        // render path, so the 422/429 rejections below must render their mapped envelopes as CI/prod do.
        config(['app.debug' => false]);
    }

    #[Test]
    #[DataProvider('provideInvalidResetPayloads')]
    public function when_the_submission_is_invalid_then_it_is_rejected(array $payload, string $invalidField): void
    {
        // Given an invalid reset-password submission
        // When it is submitted
        $response = $this->resetPassword($payload);

        // Then it is rejected as a validation error naming the offending field
        $this->assertValidationRejected($response, $invalidField);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function provideInvalidResetPayloads(): array
    {
        return [
            'missing token' => [['email' => 'alice@contoso.lk', 'password' => self::NEW_PASSWORD], 'token'],
            'missing email' => [['token' => 'a-token', 'password' => self::NEW_PASSWORD], 'email'],
            'malformed email' => [['token' => 'a-token', 'email' => 'not-an-email', 'password' => self::NEW_PASSWORD], 'email'],
            'missing password' => [['token' => 'a-token', 'email' => 'alice@contoso.lk'], 'password'],
            'password under eight characters' => [['token' => 'a-token', 'email' => 'alice@contoso.lk', 'password' => 'Sh0rt!'], 'password'],
            'password over the length cap' => [['token' => 'a-token', 'email' => 'alice@contoso.lk', 'password' => str_repeat('a', 256)], 'password'],
        ];
    }
}
