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

    private function listSessions(string $token): TestResponse
    {
        return $this->withToken($token)->getJson('/api/v1/me/sessions');
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
}
