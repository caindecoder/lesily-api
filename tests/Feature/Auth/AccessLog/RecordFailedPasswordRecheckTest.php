<?php

namespace Tests\Feature\Auth\AccessLog;

use Illuminate\Support\Str;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AccessLog\Enums\AccessEventReason;

class RecordFailedPasswordRecheckTest extends AuthTestCase
{
    #[Test]
    #[DataProvider('passwordRecheckProvider')]
    public function when_the_current_password_is_wrong_then_a_failed_recheck_is_logged(string $method, string $uri, string $passwordField): void
    {
        // Given a signed-in two-factor user
        $user = $this->aTwoFactorUser();
        $token = $this->aSessionFor($user)->plainTextToken;

        // When they confirm a sensitive action with the wrong current password
        $response = $this->confirmWith($token, [$method, $uri, $passwordField], 'not-my-password');

        // Then a failed re-check is logged against them
        $response->assertUnauthorized();
        $entry = $this->assertAccessLogged(AccessEvent::ReauthFailed, AccessEventReason::WrongPassword, $user);
        $this->assertAnsweredAs($entry, Response::HTTP_UNAUTHORIZED, ApiErrorCode::IncorrectPassword);
    }

    #[Test]
    #[DataProvider('passwordRecheckProvider')]
    public function when_the_current_password_is_right_then_no_failed_recheck_is_logged(string $method, string $uri, string $passwordField): void
    {
        // Given a signed-in two-factor user
        $token = $this->aSessionFor($this->aTwoFactorUser())->plainTextToken;

        // When they confirm a sensitive action with their current password
        $response = $this->confirmWith($token, [$method, $uri, $passwordField], self::LOGIN_PASSWORD);

        // Then the action goes ahead and no failed re-check is logged
        $response->assertSuccessful();
        $this->assertNoAccessLogged(AccessEvent::ReauthFailed);
    }

    /**
     * Every action that re-checks the signed-in user's password (PasswordVerifier's callers).
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function passwordRecheckProvider(): array
    {
        return [
            'change password' => ['PUT', '/api/v1/me/password', 'currentPassword'],
            'disable two-factor' => ['DELETE', '/api/v1/auth/2fa', 'password'],
            'regenerate recovery codes' => ['POST', '/api/v1/auth/2fa/recovery-codes', 'password'],
            'log out other devices' => ['POST', '/api/v1/auth/logout-all', 'password'],
        ];
    }

    /** @param  array{0: string, 1: string, 2: string}  $action */
    private function confirmWith(string $token, array $action, string $password): TestResponse
    {
        [$method, $uri, $passwordField] = $action;

        return $this->withToken($token)->json(
            $method,
            $uri,
            [$passwordField => $password, 'newPassword' => self::NEW_PASSWORD],
            ['Idempotency-Key' => (string) Str::uuid()],
        );
    }
}
