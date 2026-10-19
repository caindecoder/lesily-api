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
}
