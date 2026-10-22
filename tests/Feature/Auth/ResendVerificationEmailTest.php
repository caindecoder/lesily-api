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
    public function when_the_person_asks_again_within_the_minute_then_they_are_told_how_long_to_wait(): void
    {
        // Given the owner has just asked for a new link
        Notification::fake();
        $this->askForNewLinkAs($this->owner);

        // When they ask again inside the same minute
        $response = $this->askForNewLinkAs($this->owner);

        // Then the request is refused with how long to wait
        $this->assertThrottled($response);
    }

    #[Test]
    public function when_the_shared_sign_in_limit_for_the_ip_is_used_up_then_the_resend_is_refused(): void
    {
        // Given the IP has used up the sign-in limit it shares with the resend
        Notification::fake();
        $this->useUpSharedSignInLimit();

        // When the owner asks for a new link from that IP
        $response = $this->askForNewLinkAs($this->owner);

        // Then the request is refused with how long to wait
        $this->assertThrottled($response);
    }

    #[Test]
    public function when_the_email_is_already_verified_then_nothing_is_sent(): void
    {
        // Given a person whose email is already verified
        Notification::fake();
        $verified = User::factory()->create();

        // When they ask for a new verification link
        $response = $this->askForNewLinkAs($verified);

        // Then the request succeeds and nothing is sent
        $this->assertNothingSent($response);
    }

    #[Test]
    public function when_one_person_has_used_their_minute_then_another_person_on_the_same_ip_may_ask(): void
    {
        // Given the owner has used their one link this minute, and a second
        // unverified person shares the owner's IP
        Notification::fake();
        $this->askForNewLinkAs($this->owner);
        $other = User::factory()->unverified()->create();

        // When the second person asks for a new link
        $response = $this->askForNewLinkAs($other);

        // Then a fresh link is sent to them
        $this->assertNewLinkSentTo($response, $other, 1);
    }

    #[Test]
    public function when_the_minute_has_passed_then_the_person_may_ask_again(): void
    {
        // Given the owner asked for a link and the minute has since passed
        Notification::fake();
        $this->askForNewLinkAs($this->owner);
        $this->travel(61)->seconds();

        // When they ask again
        $response = $this->askForNewLinkAs($this->owner);

        // Then a second fresh link is sent
        $this->assertNewLinkSentTo($response, $this->owner, 2);
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

    protected function useUpSharedSignInLimit(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/auth/login', []);
        }
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

    protected function assertNothingSent(TestResponse $response): void
    {
        $response->assertNoContent();
        Notification::assertNothingSent();
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
