<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;

class ResendVerificationEmailTest extends AuthTestCase
{
    protected const RESEND_URL = '/api/v1/auth/email/verification-notification';

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->unverified()->create();
    }

    #[Test]
    public function when_a_guest_asks_for_a_new_link_then_they_must_sign_in(): void
    {
        // Given no one is signed in
        Notification::fake();

        // When a new verification link is asked for
        $response = $this->askForNewLinkWithoutSigningIn();

        // Then the request is refused and nothing is sent
        $this->assertUnauthenticated($response);
        Notification::assertNothingSent();
    }

    protected function askForNewLinkWithoutSigningIn(): TestResponse
    {
        return $this->postJson(self::RESEND_URL);
    }
}
