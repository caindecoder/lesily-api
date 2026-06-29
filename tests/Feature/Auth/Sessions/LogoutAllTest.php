<?php

namespace Tests\Feature\Auth\Sessions;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
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

    // Sad — wrong password is the re-auth gate: it is refused and NONE of the user's sessions
    // are revoked (the Minimal Guarantee — a failed confirm must leave every device logged in).
    #[Test]
    public function when_the_password_is_incorrect_then_all_sessions_are_preserved(): void
    {
        // Given a user with three active sessions (the current one plus two others)
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');
        $this->aSessionFor($user, 'tablet');

        // When they confirm logout-all with the wrong password
        $response = $this->logoutAll($current->plainTextToken, 'not-my-password');

        // Then it is refused and all three sessions remain intact
        $this->assertRefusedWith($response, ApiErrorCode::IncorrectPassword);
        $response->assertStatus(401); // literal pins the 401 contract independent of the enum
        $response->assertJsonPath('message', 'Incorrect password. Please try again.'); // pins the bootstrap copy (Infection-blind)
        $this->assertSessionsPreserved($user, 3);
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

    private function logoutAll(string $token, string $password): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/auth/logout-all', ['password' => $password]);
    }

    /**
     * No session was revoked: the user still holds exactly $expected tokens. The "nothing
     * revoked" oracle for the refused / no-op paths — goes RED if a revoke ever runs before
     * (or instead of) the password gate (the Minimal Guarantee).
     */
    protected function assertSessionsPreserved(User $user, int $expected): void
    {
        $this->assertSame($expected, $user->tokens()->count());
    }
}
