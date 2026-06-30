<?php

namespace Tests\Feature\Auth\Password;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Http\Responses\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ChangePasswordTest extends AuthTestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'New-Str0ng-Pass!';

    protected function setUp(): void
    {
        parent::setUp();

        // Error-path render parity (amendment M): APP_DEBUG=true (the local default)
        // masks the prod render path. The 401/422/429 rejections below must render their
        // mapped envelopes the way CI/prod do.
        config(['app.debug' => false]);
    }

    #[Test]
    public function when_no_session_is_provided_then_the_request_is_unauthenticated(): void
    {
        // Given no authenticated token

        // When a password change is attempted
        $response = $this->putJson('/api/v1/me/password', [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the Sanctum guard rejects it before any work runs
        $this->assertUnauthenticated($response);
    }

    #[Test]
    public function when_the_current_password_is_incorrect_then_the_password_is_unchanged(): void
    {
        // Given an authenticated user signed in on two devices — the current one plus one other
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user, 'web');
        $this->aSessionFor($user, 'mobile');

        // When they submit the wrong current password
        $response = $this->changePassword($current->plainTextToken, [
            'currentPassword' => 'not-my-password',
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then it is refused, the password is left intact, and NO session is revoked (Minimal Guarantee)
        $this->assertRefusedWith($response, ApiErrorCode::IncorrectPassword);
        $response->assertStatus(401); // literal pins the 401 contract independent of the enum
        $response->assertJsonPath('message', 'Incorrect password. Please try again.'); // pins the bootstrap copy (Infection-blind)
        $this->assertPasswordUnchanged($user, 2);
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function changePassword(string $token, array $payload, ?string $idempotencyKey = null): TestResponse
    {
        return $this->withToken($token)->putJson('/api/v1/me/password', $payload, [
            'Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid(),
        ]);
    }

    /**
     * The change was refused: the original password still authenticates, the attempted new
     * password was NOT stored, and not a single session was revoked (the Minimal Guarantee —
     * goes RED if any write or revoke leaks past a refused change).
     */
    protected function assertPasswordUnchanged(User $user, int $expectedSessions): void
    {
        $stored = User::query()->whereKey($user->getKey())->firstOrFail();
        $this->assertTrue(Hash::check(self::LOGIN_PASSWORD, $stored->password), 'The original password must still authenticate.');
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $stored->password), 'A rejected new password must not be stored.');
        $this->assertSame($expectedSessions, $user->tokens()->count(), 'A rejected change must revoke no sessions.');
    }
}
