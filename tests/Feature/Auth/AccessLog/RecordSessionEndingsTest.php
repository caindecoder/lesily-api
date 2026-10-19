<?php

namespace Tests\Feature\Auth\AccessLog;

use Illuminate\Http\Response;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\AccessLog\Enums\AccessEvent;

class RecordSessionEndingsTest extends AuthTestCase
{
    #[Test]
    public function when_an_unknown_session_is_revoked_then_no_revocation_is_logged(): void
    {
        // Given a signed-in user
        $token = $this->aSessionFor($this->aRegisteredUser())->plainTextToken;

        // When they revoke a session that does not exist
        $response = $this->withToken($token)->deleteJson('/api/v1/me/sessions/999999');

        // Then it is not found and no revocation is logged
        $response->assertNotFound();
        $this->assertNoAccessLogged(AccessEvent::SessionRevoked);
    }

    #[Test]
    public function when_the_other_sessions_cannot_be_revoked_then_no_revocation_is_logged(): void
    {
        // Given a signed-in user, and a session revoker that fails
        $token = $this->aSessionFor($this->aRegisteredUser())->plainTextToken;
        $this->bindThrowingTokenRevoker();

        // When they log out their other devices
        $response = $this->withToken($token)->postJson('/api/v1/auth/logout-all', ['password' => self::LOGIN_PASSWORD]);

        // Then it fails and no revocation is logged
        $response->assertInternalServerError();
        $this->assertNoAccessLogged(AccessEvent::OtherSessionsRevoked);
    }

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

    #[Test]
    public function when_the_user_revokes_one_session_then_the_revocation_is_logged(): void
    {
        // Given a user signed in on two devices
        $user = $this->aRegisteredUser();
        $token = $this->aSessionFor($user, 'web')->plainTextToken;
        $other = $this->aSessionFor($user, 'mobile')->accessToken->getKey();

        // When they revoke the other device's session
        $response = $this->withToken($token)->deleteJson("/api/v1/me/sessions/{$other}");

        // Then the revocation is logged
        $response->assertNoContent();
        $entry = $this->assertAccessLogged(AccessEvent::SessionRevoked, null, $user);
        $this->assertAnsweredAs($entry, Response::HTTP_NO_CONTENT, null);
    }
}
