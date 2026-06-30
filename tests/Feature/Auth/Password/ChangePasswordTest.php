<?php

namespace Tests\Feature\Auth\Password;

use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
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
}
