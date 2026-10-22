<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use App\Foundation\Auth\Notifications\EmailVerificationLinkNotification;

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

    #[Test]
    public function when_an_unverified_owner_asks_for_a_new_link_then_a_fresh_link_is_sent(): void
    {
        // Given the owner has not verified their email
        Notification::fake();

        // When they ask for a new verification link
        $response = $this->askForNewLinkAs($this->owner);

        // Then a fresh link is sent to them
        $this->assertNewLinkSentTo($response, $this->owner, 1);
    }

    #[Test]
    public function when_an_unverified_owner_asks_for_a_new_link_then_the_email_is_queued_not_sent_inline(): void
    {
        // Given the email can only go out through the queue
        Queue::fake();

        // When the unverified owner asks for a new verification link
        $response = $this->askForNewLinkAs($this->owner);

        // Then the link waits on the queue instead of being sent inside the request
        $this->assertNewLinkQueuedFor($response, $this->owner);
    }

    protected function askForNewLinkWithoutSigningIn(): TestResponse
    {
        return $this->postJson(self::RESEND_URL);
    }

    protected function askForNewLinkAs(User $person): TestResponse
    {
        Sanctum::actingAs($person);

        return $this->postJson(self::RESEND_URL);
    }

    protected function assertNewLinkSentTo(TestResponse $response, User $person, int $times): void
    {
        $response->assertNoContent();
        Notification::assertSentToTimes($person, EmailVerificationLinkNotification::class, $times);
    }

    protected function assertNewLinkQueuedFor(TestResponse $response, User $person): void
    {
        $response->assertNoContent();
        Queue::assertPushed(
            SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job) => $job->notification instanceof EmailVerificationLinkNotification
                && $job->notifiables->contains($person),
        );
    }
}
