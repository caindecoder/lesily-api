<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ViewProfileTest extends AuthTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_no_token_provided_then_request_is_unauthenticated(): void
    {
        // Given no authentication token is provided

        // When a request is sent to GET /api/v1/me
        $response = $this->viewProfileWithoutAuth();

        // Then the request is rejected as unauthenticated
        $this->assertUnauthenticated($response);
    }

    #[Test]
    public function when_another_user_exists_then_only_own_profile_is_returned(): void
    {
        // Given an authenticated user Alice and another user Bob in the system
        $alice = User::factory()->named('Alice')->create();
        $bob = User::factory()->named('Bob')->create();

        // When Alice requests GET /api/v1/me
        $response = $this->viewProfile($alice);

        // Then the response contains only Alice's data and no field references Bob
        $this->assertOnlyOwnProfile($response, $alice, $bob);
    }

    protected function assertOnlyOwnProfile(TestResponse $response, User $owner, User $other): void
    {
        $response->assertOk();
        $response->assertJsonPath('data.id', $owner->id);
        $response->assertJsonPath('data.email', $owner->email);
        $this->assertStringNotContainsString($other->id, (string) $response->getContent());
        $this->assertStringNotContainsString($other->email, (string) $response->getContent());
    }
}
