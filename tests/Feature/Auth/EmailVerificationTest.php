<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use App\Foundation\Auth\Events\UserEmailVerified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class EmailVerificationTest extends AuthTestCase
{
    use RefreshDatabase;

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
        // Given an unverified owner and a tampered verification link
        $user = User::factory()->unverified()->create();
        $tamperedLink = $this->verificationLinkFor($user).'tampered';

        // When the tampered link is visited
        $response = $this->getJson($tamperedLink);

        // Then it is rejected and the email stays unverified
        $response->assertForbidden();
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

    // Happy — a valid signed link verifies the owner's email exactly once.
    #[Test]
    public function when_the_owner_follows_a_valid_verification_link_then_their_email_is_verified(): void
    {
        // Given an unverified owner
        $user = User::factory()->unverified()->create();

        // When the owner visits a valid verification link
        $response = $this->getJson($this->verificationLinkFor($user));

        // Then the email is verified, recorded once
        $response->assertOk();
        $this->assertEmailVerifiedOnce($user);
    }

    protected function verificationLinkFor(User $user, ?CarbonImmutable $expiresAt = null): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            $expiresAt ?? CarbonImmutable::now()->addHour(),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );
    }

    protected function assertEmailNotVerified(User $user): void
    {
        $this->assertNull($user->fresh()->email_verified_at);
    }

    protected function assertEmailVerifiedOnce(User $user): void
    {
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', UserEmailVerified::class)
            ->where('event_properties->userId', $user->id)
            ->count());
    }
}
