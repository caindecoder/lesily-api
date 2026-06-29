<?php

namespace Tests\Feature\Auth\Sessions;

use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LogoutAllTest extends AuthTestCase
{
    use RefreshDatabase;

    // Sad — the logout-all endpoint is guarded: a request with no token is rejected by the
    // Sanctum guard before any password check or revocation runs (proves auth:sanctum is on).
    #[Test]
    public function when_no_token_is_presented_then_logging_out_all_is_rejected(): void
    {
        // Given no authenticated token

        // When logging out all devices is attempted
        $response = $this->postJson('/api/v1/auth/logout-all', ['password' => self::LOGIN_PASSWORD]);

        // Then the Sanctum guard rejects it
        $this->assertUnauthenticated($response);
    }

    // Sad — the password is the re-auth gate: an authenticated request that omits it (or sends
    // a blank one) is rejected as a validation error before any revocation runs.
    #[Test]
    #[DataProvider('provideInvalidLogoutAllPayloads')]
    public function when_the_password_is_missing_then_the_request_is_rejected(array $payload): void
    {
        // Given an authenticated user holding a live session
        $token = $this->aSessionFor($this->aRegisteredUser())->plainTextToken;

        // When logout-all is submitted without a valid password
        $response = $this->withToken($token)->postJson('/api/v1/auth/logout-all', $payload);

        // Then the request is rejected as a validation error naming the password
        $this->assertValidationRejected($response, 'password');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function provideInvalidLogoutAllPayloads(): array
    {
        return [
            'missing password' => [[]],
            'blank password' => [['password' => '']],
        ];
    }
}
