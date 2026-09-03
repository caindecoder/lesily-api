<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use Illuminate\Auth\Notifications\VerifyEmail;
use App\Foundation\Auth\Events\UserEmailVerified;
use Spatie\EventSourcing\StoredEvents\HandleStoredEventJob;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class EmailVerificationTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The verification email is a boundary side effect — spy it, never send.
        Notification::fake();
    }

    // Sad — a tampered signed link must never verify the email.
    #[Test]
    public function when_the_verification_link_signature_is_invalid_then_verification_is_rejected(): void
    {
        // Given production error rendering (debug off — debug=true masks the
        // envelope path by letting Laravel render HttpExceptions natively) and an
        // unverified owner with a tampered verification link
        config(['app.debug' => false]);
        $user = User::factory()->unverified()->create();
        $tamperedLink = $this->verificationLinkFor($user).'tampered';

        // When the tampered link is visited
        $response = $this->getJson($tamperedLink);

        // Then it is rejected as a forbidden envelope and the email stays unverified
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'INVALID_SIGNATURE');
        $this->assertEmailNotVerified($user);
    }

    // Edge — re-following the link after verification is a harmless no-op.
    #[Test]
    public function when_the_email_is_already_verified_then_re_verifying_records_no_further_change(): void
    {
        // Given an owner who has already verified via the link
        $user = User::factory()->unverified()->create();
        $link = $this->verificationLinkFor($user);
        $this->getJson($link);

        // When the same link is followed again
        $response = $this->getJson($link);

        // Then it still succeeds but records no second verification
        $response->assertOk();
        $this->assertEmailVerifiedOnce($user);
    }

    // Happy — a valid signed link verifies the owner's email exactly once, stamped now.
    #[Test]
    public function when_the_owner_follows_a_valid_verification_link_then_their_email_is_verified(): void
    {
        // Given an unverified owner at a known instant
        $this->travelTo('2026-06-14 12:00:00');
        $user = User::factory()->unverified()->create();

        // When the owner visits a valid verification link
        $response = $this->getJson($this->verificationLinkFor($user));

        // Then the email is verified once and stamped with the verification time
        $response->assertOk();
        $this->assertEmailVerifiedOnce($user);
        $this->assertSame('2026-06-14 12:00:00', $user->refresh()->email_verified_at?->toDateTimeString());
    }

    // Side-effect — registering as owner sends the verification email (Pattern D).
    #[Test]
    public function when_an_owner_registers_then_a_verification_email_is_sent(): void
    {
        // Given the RBAC catalog the owner pivot resolves against
        $this->seedRbacCatalog();

        // When a guest registers as owner
        $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload());

        // Then a verification email is sent to the new owner
        $this->assertVerificationEmailSentTo('nimal@contoso.lk');
    }

    // Side-effect (deferral) — the verification email is dispatched as a queued
    // after-commit job, not sent inline; that deferral is what lets a rolled-back
    // registration send nothing (after_commit discards the job — config property).
    #[Test]
    public function when_an_owner_registers_then_the_verification_email_is_queued_not_sent_inline(): void
    {
        // Given the email handler can only run off the queue
        Queue::fake();
        $this->seedRbacCatalog();

        // When a guest registers as owner
        $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload());

        // Then the handler is deferred to the queue and nothing is sent inline
        Queue::assertPushed(HandleStoredEventJob::class);
        Notification::assertNothingSent();
    }

    // Config contract — the deferral above only discards the email on rollback if
    // the real queue connection waits for commit. The sync test queue can't exercise
    // that, so pin the connection's after_commit flag against an accidental revert.
    #[Test]
    public function the_async_queue_connection_defers_jobs_until_after_commit(): void
    {
        $this->assertTrue(config('queue.connections.database.after_commit'));
    }

    protected function verificationLinkFor(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            CarbonImmutable::now()->addHour(),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );
    }

    protected function assertEmailNotVerified(User $user): void
    {
        $this->assertNull($user->refresh()->email_verified_at);
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('event_class', UserEmailVerified::class)
            ->where('event_properties->userId', $user->id)
            ->count());
    }

    protected function assertEmailVerifiedOnce(User $user): void
    {
        $this->assertNotNull($user->refresh()->email_verified_at);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', UserEmailVerified::class)
            ->where('event_properties->userId', $user->id)
            ->count());
    }

    protected function assertVerificationEmailSentTo(string $email): void
    {
        $owner = User::query()->where('email', $email)->sole();

        Notification::assertSentTo($owner, VerifyEmail::class);
    }
}
