<?php

namespace Tests\Feature\Auth\TwoFactor;

use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
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
}
