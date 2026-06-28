<?php

namespace Tests\Feature\Auth\Sessions;

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ViewSessionsTest extends AuthTestCase
{
    use RefreshDatabase;

    // Sad — the session-list endpoint is guarded: a request with no token is
    // rejected by the Sanctum guard before any listing runs (proves auth:sanctum).
    #[Test]
    public function when_no_token_is_presented_then_the_session_list_is_rejected(): void
    {
        // Given no authenticated token

        // When the active sessions are requested
        $response = $this->getJson('/api/v1/me/sessions');

        // Then the Sanctum guard rejects it
        $this->assertUnauthenticated($response);
    }

    // Sad/isolation — the list is scoped to the requester. Another user's sessions
    // are never exposed. The UC's cross-tenant scenario collapses here: account-level
    // tokens carry no company scope, so "own only" IS the tenant boundary. The foreign
    // session has a future expiry so that dropping the OR-grouping would leak it.
    #[Test]
    public function when_listing_sessions_then_only_the_users_own_sessions_are_returned(): void
    {
        // Given two users, each holding an active session
        $viewer = $this->aRegisteredUser();
        $other = $this->aRegisteredUser('bob@contoso.lk');
        $viewerToken = $this->aSessionFor($viewer)->plainTextToken;
        $foreignTokenId = $this->aSessionFor($other, 'mobile')->accessToken->getKey();

        // When the viewer lists their active sessions
        $response = $this->listSessions($viewerToken);

        // Then they see only their own — the other user's session is absent
        $this->assertOnlyOwnSessions($response, $viewer, $foreignTokenId);
    }

    // Edge — the list shows only ACTIVE sessions: an expired token (a dead session
    // whose row lingers until pruned) is excluded. Sanctum won't authenticate it and
    // the UI must not surface terminated devices (wireframe WF-1-024 §8).
    #[Test]
    public function when_a_session_has_expired_then_it_is_excluded_from_the_list(): void
    {
        // Given a user with one active session and one expired session
        $user = $this->aRegisteredUser();
        $activeToken = $this->aSessionFor($user)->plainTextToken;
        $expiredTokenId = $this->anExpiredSessionFor($user, 'old-laptop')->accessToken->getKey();

        // When they list their active sessions
        $response = $this->listSessions($activeToken);

        // Then only the active session remains — the expired one is excluded
        $this->assertSessionExcluded($response, $expiredTokenId);
    }

    // Edge — the requesting token is flagged as the current session; the user's other
    // sessions are not. "Current" is the token that authenticated THIS request (Sanctum
    // currentAccessToken), letting the UI mark "this device" and guard it from self-revoke.
    #[Test]
    public function when_listing_sessions_then_the_requesting_token_is_flagged_as_current(): void
    {
        // Given a user holding two active sessions
        $user = $this->aRegisteredUser();
        $currentSession = $this->aSessionFor($user);
        $this->aSessionFor($user, 'mobile');

        // When they list their sessions using one of those tokens
        $response = $this->listSessions($currentSession->plainTextToken);

        // Then only the requesting token is flagged as the current session
        $this->assertExactlyCurrentSession($response, $currentSession->accessToken->getKey());
    }

    // Happy — a session is listed with its full row shape: id, the device it was minted
    // for, when it was last active, and the current-session flag. This is exactly what
    // the Active Sessions screen renders per row (wireframe WF-1-024).
    #[Test]
    public function when_a_user_has_active_sessions_then_each_is_listed_with_its_device_and_last_active_time(): void
    {
        // Given a user with one active session minted for a named device. Freeze on a
        // whole-second boundary: last_used_at is a second-precision column, so a frozen
        // sub-second now() would not round-trip through the database.
        $this->travelTo(now()->startOfSecond());
        $user = $this->aRegisteredUser();
        $deviceName = 'Chrome on Windows';
        $session = $this->aSessionFor($user, $deviceName);

        // When they list their active sessions — minted earlier, listed now, so
        // last_used_at (stamped at list time) is provably distinct from created_at.
        $this->travel(5)->minutes();
        $response = $this->listSessions($session->plainTextToken);

        // Then the row carries its id, device, last-active time, and current flag
        $this->assertSessionListed($response, $session->accessToken->getKey(), $deviceName);
    }

    private function listSessions(string $token): TestResponse
    {
        return $this->withToken($token)->getJson('/api/v1/me/sessions');
    }

    /**
     * Mint a production-faithful active session: an explicit ~90-day expiry exactly as
     * SessionIssuer stamps it. createToken alone leaves expires_at null, which no real
     * login produces — so the suite must set it to exercise the live "future expiry"
     * filter arm. Returns the NewAccessToken so callers get both the plain text (to
     * authenticate) and the token id (to assert on).
     */
    private function aSessionFor(User $user, string $name = 'web'): NewAccessToken
    {
        return $user->createToken($name, ['*'], now()->addMinutes(Config::integer('sanctum.expiration')));
    }

    /**
     * Mint a session whose explicit expiry is already in the past — a dead session whose
     * row lingers until pruned.
     */
    private function anExpiredSessionFor(User $user, string $name): NewAccessToken
    {
        return $user->createToken($name, ['*'], now()->subDay());
    }

    /**
     * The list contains exactly the viewer's own tokens and never the foreign one.
     */
    protected function assertOnlyOwnSessions(TestResponse $response, User $viewer, int $foreignTokenId): void
    {
        $response->assertOk();
        $tokenIds = $response->collect('data')->pluck('token_id')->all();
        $this->assertEqualsCanonicalizing($viewer->tokens()->pluck('id')->all(), $tokenIds);
        $this->assertNotContains($foreignTokenId, $tokenIds);
    }

    /**
     * The excluded token is absent and only the single active session remains.
     */
    protected function assertSessionExcluded(TestResponse $response, int $excludedTokenId): void
    {
        $response->assertOk();
        $tokenIds = $response->collect('data')->pluck('token_id')->all();
        $this->assertNotContains($excludedTokenId, $tokenIds);
        $this->assertCount(1, $tokenIds);
    }

    /**
     * Exactly one session is flagged current, and it is the requesting token.
     */
    protected function assertExactlyCurrentSession(TestResponse $response, int $currentTokenId): void
    {
        $response->assertOk();
        $currentTokenIds = $response->collect('data')->where('current_session', true)->pluck('token_id')->all();
        $this->assertSame([$currentTokenId], $currentTokenIds);
    }

    /**
     * The single listed session carries its id, device name, last-active time, and
     * current-session flag — the full shape one session row renders.
     */
    protected function assertSessionListed(TestResponse $response, int $tokenId, string $deviceName): void
    {
        $response->assertOk()
            ->assertJsonPath('data.0.token_id', $tokenId)
            ->assertJsonPath('data.0.device_name', $deviceName)
            ->assertJsonPath('data.0.last_used_at', now()->toJSON())
            ->assertJsonPath('data.0.current_session', true);
    }
}
