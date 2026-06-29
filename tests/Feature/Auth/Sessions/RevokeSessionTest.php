<?php

namespace Tests\Feature\Auth\Sessions;

use Laravel\Sanctum\NewAccessToken;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Http\Responses\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RevokeSessionTest extends AuthTestCase
{
    use RefreshDatabase;

    // Sad — the revoke endpoint is guarded: a request with no token is rejected by the
    // Sanctum guard before any deletion runs (proves auth:sanctum is on the route).
    #[Test]
    public function when_no_token_is_provided_then_the_request_is_rejected_as_unauthenticated(): void
    {
        // Given no authenticated token

        // When a session revocation is attempted
        $response = $this->deleteJson('/api/v1/me/sessions/1');

        // Then the Sanctum guard rejects it
        $this->assertUnauthenticated($response);
    }

    // Sad — revoking a token id that is not in the caller's own set is rejected as
    // not-found. The user-scoped lookup yields nothing, so the manage surface never
    // reveals whether an id exists outside the caller's own sessions.
    #[Test]
    public function when_revoking_an_unknown_session_then_it_is_not_found(): void
    {
        // Given a user holding one active session
        $user = $this->aRegisteredUser();
        $session = $this->aSessionFor($user);

        // When they revoke an id that does not exist (their own id, shifted past the last row)
        $response = $this->revokeSession($session->plainTextToken, $session->accessToken->getKey() + 1);

        // Then it is not found
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
    }

    // Sad / isolation — a token owned by another user is never revocable through the caller's
    // manage surface: the user-scoped lookup misses it, so it 404s exactly like an unknown id
    // and the foreign session is left intact and still authenticating. Account-level tokens
    // carry no company scope, so "own only" IS the isolation guarantee.
    #[Test]
    public function when_revoking_a_session_owned_by_another_user_then_it_is_not_found_and_that_session_survives(): void
    {
        // Given two users, each holding an active session
        $caller = $this->aRegisteredUser();
        $other = $this->aRegisteredUser('bob@contoso.lk');
        $callerToken = $this->aSessionFor($caller)->plainTextToken;
        $foreign = $this->aSessionFor($other, 'mobile');

        // When the caller tries to revoke the other user's session
        $response = $this->revokeSession($callerToken, $foreign->accessToken->getKey());

        // Then it is not found and the other user's session is untouched
        $this->assertForeignSessionUntouched($response, $foreign);
    }

    // Happy boundary — a user may revoke the very session they are authenticating with: the
    // token is hard-deleted (204) and immediately stops authenticating, so the manage surface
    // doubles as "log out this device" (api-1-users row 12).
    #[Test]
    public function when_revoking_the_users_current_session_then_it_is_removed_and_that_session_stops_authenticating(): void
    {
        // Given a user holding a single active session
        $user = $this->aRegisteredUser();
        $session = $this->aSessionFor($user);

        // When they revoke that same (current) session
        $response = $this->revokeSession($session->plainTextToken, $session->accessToken->getKey());

        // Then it is gone and no longer authenticates
        $this->assertSessionRevoked($response, $session);
    }

    // Happy — revoking one of the user's other devices removes only that session; the device
    // they are currently using keeps working. This is the core "log out my other phone" flow.
    #[Test]
    public function when_revoking_one_of_the_users_own_sessions_then_it_is_removed_and_the_others_remain_active(): void
    {
        // Given a user with two active sessions: the current one and another device
        $user = $this->aRegisteredUser();
        $current = $this->aSessionFor($user, 'web');
        $other = $this->aSessionFor($user, 'mobile');

        // When they revoke the other device from their current session
        $response = $this->revokeSession($current->plainTextToken, $other->accessToken->getKey());

        // Then only that session is gone — the current session still authenticates
        $this->assertOnlyTargetSessionRevoked($response, $other, $current->plainTextToken);
    }

    private function revokeSession(string $token, int|string $tokenId): TestResponse
    {
        return $this->withToken($token)->deleteJson("/api/v1/me/sessions/{$tokenId}");
    }

    /**
     * The foreign session is refused as not-found, its row remains, and it still
     * authenticates — proving the revoke never reached another user's token.
     */
    protected function assertForeignSessionUntouched(TestResponse $response, NewAccessToken $foreign): void
    {
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreign->accessToken->getKey()]);
        $this->probeWith($foreign->plainTextToken)->assertOk();
    }

    /**
     * The revoked session succeeds with no content, its row is gone, and the token no longer
     * authenticates (forgetGuards forces a fresh DB re-check).
     */
    protected function assertSessionRevoked(TestResponse $response, NewAccessToken $session): void
    {
        $response->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $session->accessToken->getKey()]);
        $this->assertUnauthenticated($this->probeWith($session->plainTextToken));
    }

    /**
     * Only the targeted session is revoked: it succeeds and is dead, while the survivor token
     * still authenticates.
     */
    protected function assertOnlyTargetSessionRevoked(TestResponse $response, NewAccessToken $target, string $survivorToken): void
    {
        $this->assertSessionRevoked($response, $target);
        $this->probeWith($survivorToken)->assertOk();
    }
}
