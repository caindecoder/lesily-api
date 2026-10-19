<?php

namespace Tests\Feature\Auth\AccessLog;

use Illuminate\Http\Response;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\AccessLog\Enums\AccessEvent;

class RecordSessionEndingsTest extends AuthTestCase
{
    #[Test]
    public function when_the_user_logs_out_then_the_logout_is_logged(): void
    {
        // Given a signed-in user
        $user = $this->aRegisteredUser();
        $token = $this->aSessionFor($user)->plainTextToken;

        // When they log out
        $response = $this->withToken($token)->postJson('/api/v1/auth/logout');

        // Then the logout is logged
        $response->assertNoContent();
        $entry = $this->assertAccessLogged(AccessEvent::LoggedOut, null, $user);
        $this->assertAnsweredAs($entry, Response::HTTP_NO_CONTENT, null);
    }

    #[Test]
    public function when_the_user_logs_out_other_devices_then_the_revocation_is_logged(): void
    {
        // Given a user signed in on two devices
        $user = $this->aRegisteredUser();
        $token = $this->aSessionFor($user, 'web')->plainTextToken;
        $this->aSessionFor($user, 'mobile');

        // When they log out their other devices from this one
        $response = $this->withToken($token)->postJson('/api/v1/auth/logout-all', ['password' => self::LOGIN_PASSWORD]);

        // Then the revocation of the other sessions is logged
        $response->assertOk();
        $entry = $this->assertAccessLogged(AccessEvent::OtherSessionsRevoked, null, $user);
        $this->assertAnsweredAs($entry, Response::HTTP_OK, null);
    }
}
