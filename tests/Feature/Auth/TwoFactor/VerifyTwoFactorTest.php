<?php

namespace Tests\Feature\Auth\TwoFactor;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\Auth\Totp\LoginChallengeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

class VerifyTwoFactorTest extends AuthTestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('provideInvalidVerifyPayloads')]
    public function when_required_fields_are_missing_then_the_request_is_rejected(array $payload, string $invalidField): void
    {
        // Given an invalid verify payload (a required field missing or blank)
        // When it is submitted to the verify endpoint
        $response = $this->postJson('/api/v1/auth/2fa/verify', $payload);

        // Then the request is rejected as a validation error naming the field
        $this->assertValidationRejected($response, $invalidField);
    }

    #[Test]
    public function when_the_challenge_is_unknown_or_expired_then_verification_is_refused(): void
    {
        // Given no live challenge for the submitted id (never created, or expired)
        // When a verify is attempted against it
        $response = $this->verify('not-a-live-challenge', '123456');

        // Then verification is refused as a missing challenge and no token is issued
        $this->assertRefusedWith($response, ApiErrorCode::TwoFactorChallengeNotFound);
        $response->assertJsonMissingPath('data.token');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidVerifyPayloads(): array
    {
        return [
            'missing challenge id' => [['code' => '123456'], 'challenge_id'],
            'missing code' => [['challenge_id' => 'a-challenge-id'], 'code'],
            'blank code' => [['challenge_id' => 'a-challenge-id', 'code' => ''], 'code'],
        ];
    }

    protected function verify(string $challengeId, string $code): TestResponse
    {
        return $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ]);
    }

    protected function openChallenge(User $user): string
    {
        return app(LoginChallengeStore::class)->create($user->id);
    }

    protected function assertRefusedWith(TestResponse $response, ApiErrorCode $code): void
    {
        $response->assertStatus($code->status());
        $response->assertJsonPath('error_code', $code->value);
    }
}
