<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
}
