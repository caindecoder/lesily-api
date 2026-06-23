<?php

namespace Tests\Feature\Auth\TwoFactor;

use Laravel\Sanctum\Sanctum;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RegenerateRecoveryCodesTest extends AuthTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_no_token_is_presented_then_regeneration_is_rejected(): void
    {
        // Given no authenticated token

        // When a regeneration is attempted
        $response = $this->postJson('/api/v1/auth/2fa/recovery-codes', ['password' => self::LOGIN_PASSWORD]);

        // Then the Sanctum guard rejects it before any work runs
        $this->assertUnauthenticated($response);
    }

    #[Test]
    #[DataProvider('provideInvalidRegeneratePayloads')]
    public function when_the_password_is_missing_then_the_request_is_rejected(array $payload): void
    {
        // Given an authenticated 2FA user
        Sanctum::actingAs($this->aTwoFactorUser());

        // When a regeneration is submitted without a valid password
        $response = $this->postJson('/api/v1/auth/2fa/recovery-codes', $payload);

        // Then the request is rejected as a validation error naming the password
        $this->assertValidationRejected($response, 'password');
    }

    #[Test]
    public function when_two_factor_is_not_enabled_then_regeneration_is_rejected(): void
    {
        // Given an authenticated user who has never enabled 2FA
        Sanctum::actingAs($this->aRegisteredUser());

        // When they attempt to regenerate recovery codes with their (correct) password
        $response = $this->postJson('/api/v1/auth/2fa/recovery-codes', ['password' => self::LOGIN_PASSWORD]);

        // Then it is refused — there is no 2FA to manage
        $this->assertRefusedWith($response, ApiErrorCode::TwoFactorNotEnabled);
        $response->assertStatus(422); // literal pins the 422 contract independent of the enum
        $response->assertJsonPath('message', 'Two-factor authentication is not enabled.');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function provideInvalidRegeneratePayloads(): array
    {
        return [
            'missing password' => [[]],
            'blank password' => [['password' => '']],
        ];
    }
}
