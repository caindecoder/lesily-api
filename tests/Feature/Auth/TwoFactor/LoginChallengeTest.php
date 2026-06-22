<?php

namespace Tests\Feature\Auth\TwoFactor;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Auth\Totp\LoginChallengeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LoginChallengeTest extends AuthTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze the clock so the advertised challenge expiry is exactly assertable.
        $this->freezeTime();
    }

    #[Test]
    public function when_a_non_two_factor_user_logs_in_then_a_usable_token_is_issued(): void
    {
        // Given a registered user without 2FA
        $user = $this->aRegisteredUser();

        // When they submit valid credentials
        $response = $this->login();

        // Then a working token is issued — the 2FA branch never intercepts non-enrolled users
        $this->assertUsableToken($response, $user);
    }

    #[Test]
    public function when_a_two_factor_user_submits_valid_credentials_then_a_challenge_is_issued_without_a_token(): void
    {
        // Given a registered user with 2FA enabled
        $user = $this->aTwoFactorUser();

        // When they submit valid credentials
        $response = $this->login();

        // Then a 2FA challenge is issued instead of a token, and it resolves to that user
        $this->assertChallengeIssued($response, $user);
    }

    protected function aTwoFactorUser(): User
    {
        return User::factory()
            ->withEmail(self::LOGIN_EMAIL)
            ->withPassword(self::LOGIN_PASSWORD)
            ->withTwoFactor()
            ->create();
    }

    protected function login(): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => self::LOGIN_EMAIL,
            'password' => self::LOGIN_PASSWORD,
        ]);
    }

    protected function assertUsableToken(TestResponse $response, User $user): void
    {
        $response->assertOk();
        $token = (string) $response->json('data.token');
        $this->assertNotEmpty($token, 'A non-2FA login must still issue a token.');
        $probe = $this->withToken($token)->getJson('/api/_test/authenticated-probe');
        $probe->assertOk();
        $probe->assertJsonPath('user_id', $user->id);
    }

    protected function assertChallengeIssued(TestResponse $response, User $user): void
    {
        $response->assertStatus(202);
        $response->assertJsonMissingPath('data.token');
        $this->assertSame(0, $user->tokens()->count(), 'No Sanctum token may be minted until 2FA verification.');
        $challengeId = (string) $response->json('data.challenge_id');
        $this->assertNotEmpty($challengeId, 'A challenge id must be returned.');
        $this->assertTrue(
            now()->addMinutes(LoginChallengeStore::TTL_MINUTES)->equalTo($response->json('data.expires_at')),
            'The challenge must advertise its real 5-minute expiry.',
        );
        $this->assertSame($user->id, app(LoginChallengeStore::class)->find($challengeId)?->userId);
    }
}
