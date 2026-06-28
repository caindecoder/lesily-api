<?php

namespace Tests\Feature\Auth\Sessions;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
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
    // tokens carry no company scope, so "own only" IS the tenant boundary.
    #[Test]
    public function when_listing_sessions_then_only_the_users_own_sessions_are_returned(): void
    {
        // Given two users, each holding an active session
        $viewer = $this->aRegisteredUser();
        $other = $this->aRegisteredUser('bob@contoso.lk');
        $viewerToken = $this->tokenFor($viewer, 'web');
        $this->tokenFor($other, 'mobile');
        $foreignTokenId = $other->tokens()->sole()->getKey();

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
        $activeToken = $this->tokenFor($user, 'web');
        $expiredTokenId = $this->expiredTokenFor($user, 'old-laptop');

        // When they list their active sessions
        $response = $this->listSessions($activeToken);

        // Then only the active session remains — the expired one is excluded
        $this->assertExpiredSessionExcluded($response, $expiredTokenId);
    }

    // Edge — the requesting token is flagged as the current session; the user's other
    // sessions are not. "Current" is the token that authenticated THIS request (Sanctum
    // currentAccessToken), letting the UI mark "this device" and guard it from self-revoke.
    #[Test]
    public function when_listing_sessions_then_the_requesting_token_is_flagged_as_current(): void
    {
        // Given a user holding two active sessions
        $user = $this->aRegisteredUser();
        $currentToken = $this->tokenFor($user, 'web');
        $currentTokenId = $user->tokens()->sole()->getKey();
        $this->tokenFor($user, 'mobile');

        // When they list their sessions using one of those tokens
        $response = $this->listSessions($currentToken);

        // Then only the requesting token is flagged as the current session
        $this->assertExactlyCurrentSession($response, $currentTokenId);
    }

    private function listSessions(string $token): TestResponse
    {
        return $this->withToken($token)->getJson('/api/v1/me/sessions');
    }

    private function expiredTokenFor(User $user, string $name): int
    {
        return $user->createToken($name, ['*'], now()->subDay())->accessToken->getKey();
    }

    /**
     * The list contains exactly the viewer's own tokens and never the foreign one.
     */
    protected function assertOnlyOwnSessions(TestResponse $response, User $viewer, int $foreignTokenId): void
    {
        $response->assertOk();
        $tokenIds = collect($response->json('data'))->pluck('token_id')->all();
        $this->assertEqualsCanonicalizing($viewer->tokens()->pluck('id')->all(), $tokenIds);
        $this->assertNotContains($foreignTokenId, $tokenIds);
    }

    /**
     * The expired token is absent and only the single active session remains.
     */
    protected function assertExpiredSessionExcluded(TestResponse $response, int $expiredTokenId): void
    {
        $response->assertOk();
        $tokenIds = collect($response->json('data'))->pluck('token_id')->all();
        $this->assertNotContains($expiredTokenId, $tokenIds);
        $this->assertCount(1, $tokenIds);
    }

    /**
     * Exactly one session is flagged current, and it is the requesting token.
     */
    protected function assertExactlyCurrentSession(TestResponse $response, int $currentTokenId): void
    {
        $response->assertOk();
        $current = collect($response->json('data'))->where('current_session', true);
        $this->assertCount(1, $current);
        $this->assertSame($currentTokenId, $current->first()['token_id']);
    }
}
