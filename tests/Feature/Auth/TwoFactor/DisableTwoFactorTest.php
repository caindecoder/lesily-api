<?php

namespace Tests\Feature\Auth\TwoFactor;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DisableTwoFactorTest extends AuthTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_no_token_is_presented_then_disabling_is_rejected(): void
    {
        // Given no authenticated token

        // When a disable is attempted
        $response = $this->deleteJson('/api/v1/auth/2fa', ['password' => self::LOGIN_PASSWORD]);

        // Then the Sanctum guard rejects it before any work runs
        $this->assertUnauthenticated($response);
    }

    #[Test]
    #[DataProvider('provideInvalidDisablePayloads')]
    public function when_the_password_is_missing_then_the_request_is_rejected(array $payload): void
    {
        // Given an authenticated 2FA user
        Sanctum::actingAs($this->aTwoFactorUser());

        // When a disable is submitted without a valid password
        $response = $this->deleteJson('/api/v1/auth/2fa', $payload);

        // Then the request is rejected as a validation error naming the password
        $this->assertValidationRejected($response, 'password');
    }

    #[Test]
    public function when_two_factor_is_not_enabled_then_disabling_is_rejected(): void
    {
        // Given an authenticated user who has never enabled 2FA
        Sanctum::actingAs($this->aRegisteredUser());

        // When they attempt to disable 2FA with their (correct) password
        $response = $this->deleteJson('/api/v1/auth/2fa', ['password' => self::LOGIN_PASSWORD]);

        // Then it is refused — there is no 2FA to disable
        $this->assertRefusedWith($response, ApiErrorCode::TwoFactorNotEnabled);
        $response->assertStatus(422); // literal pins the 422 contract independent of the enum
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function provideInvalidDisablePayloads(): array
    {
        return [
            'missing password' => [[]],
            'blank password' => [['password' => '']],
        ];
    }

    protected function assertRefusedWith(TestResponse $response, ApiErrorCode $code): void
    {
        $response->assertStatus($code->status());
        $response->assertJsonPath('error_code', $code->value);
    }
}
