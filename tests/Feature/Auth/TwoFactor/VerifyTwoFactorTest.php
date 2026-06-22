<?php

namespace Tests\Feature\Auth\TwoFactor;

use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
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
}
