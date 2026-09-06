<?php

namespace Tests\Feature\Auth\Password;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Auth\Notifications\PasswordResetLinkNotification;

/**
 * Requesting a password-reset link (UC-1-008, forgot portion). A guest submits an email; the
 * response is an enumeration-safe 204 whether or not the email is registered, the request is
 * rate-limited 3/15min per email and 10/min per client IP, and a registered email is emailed a
 * single-use reset link.
 */
class ForgotPasswordTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Error-path render parity (amendment M): APP_DEBUG=true masks the prod render path, so
        // the 422/429 rejections below must render their mapped envelopes the way CI/prod do.
        config(['app.debug' => false]);
    }

    #[Test]
    #[DataProvider('invalidEmailProvider')]
    public function when_the_email_is_invalid_then_the_request_is_rejected(array $payload, string $expectedMessage): void
    {
        // Given an invalid forgot-password payload
        // When it is submitted
        $response = $this->forgotPassword($payload);

        // Then it is rejected as a validation error
        $this->assertValidationRejected($response, 'email', $expectedMessage);
    }

    #[Test]
    public function when_too_many_requests_are_made_then_they_are_throttled(): void
    {
        // Given a valid email (registration is irrelevant — the throttle runs before the broker)
        $email = 'guest@contoso.lk';

        // When the window's allowance of requests is used up
        $this->exhaustEmailAllowance($email);

        // Then the next request is throttled
        $this->assertThrottled($this->forgotPassword(['email' => $email]));
    }

    #[Test]
    public function when_requests_from_one_ip_exceed_the_limit_across_different_emails_then_they_are_throttled(): void
    {
        // Given one client has used up its per-IP allowance across distinct emails (every email still
        // holds its own per-email allowance, so only the per-IP limit can refuse the next request)
        $this->exhaustIpAllowance(10);

        // When an 11th request is made for yet another email
        $response = $this->forgotPassword(['email' => 'eleventh@contoso.lk']);

        // Then it is throttled
        $this->assertThrottled($response);
    }

    #[Test]
    public function when_one_email_is_throttled_then_only_that_email_is_affected(): void
    {
        // Given one email has used up its 3/15min allowance (the client IP is held constant here)
        $exhausted = 'victim@contoso.lk';
        $this->exhaustEmailAllowance($exhausted);
        $this->assertThrottled($this->forgotPassword(['email' => $exhausted]));

        // Then a case-variant of the same email shares the bucket (the key is lowercased)
        $this->assertThrottled($this->forgotPassword(['email' => 'VICTIM@contoso.lk']));

        // ...but a genuinely different email from the same client keeps its own per-email allowance
        // (its passing requests also count against the client's separate 10/min per-IP allowance;
        // refused ones record no hit)
        $this->forgotPassword(['email' => 'other@contoso.lk'])->assertNoContent();
    }

    #[Test]
    public function when_a_separate_ip_requests_a_reset_then_it_is_unaffected_by_another_ips_throttle(): void
    {
        // Given one IP has used up its per-IP allowance and is being throttled
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1']);
        $this->exhaustIpAllowance(10);
        $this->assertThrottled($this->forgotPassword(['email' => 'eleventh@contoso.lk']));

        // When a different IP makes its first request
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])
            ->forgotPassword(['email' => 'fresh@contoso.lk']);

        // Then it is not throttled by the other IP's exhausted allowance
        $response->assertNoContent();
    }

    #[Test]
    public function when_the_per_ip_window_has_elapsed_then_the_client_may_request_again(): void
    {
        // Given one client has used up its per-IP allowance and is being throttled
        $this->exhaustIpAllowance(10);
        $this->assertThrottled($this->forgotPassword(['email' => 'eleventh@contoso.lk']));

        // When the one-minute window has elapsed
        $this->travel(61)->seconds();

        // Then the client may request again
        $this->forgotPassword(['email' => 'twelfth@contoso.lk'])->assertNoContent();
    }

    #[Test]
    public function when_the_per_email_window_has_elapsed_then_the_email_may_be_requested_again(): void
    {
        // Given one email has used up its allowance and is being throttled
        $email = 'victim@contoso.lk';
        $this->exhaustEmailAllowance($email);
        $this->assertThrottled($this->forgotPassword(['email' => $email]));

        // When the fifteen-minute window has elapsed
        $this->travel(16)->minutes();

        // Then the email may be requested again
        $this->forgotPassword(['email' => $email])->assertNoContent();
    }

    #[Test]
    public function when_the_email_is_unregistered_then_the_response_is_identical_and_no_link_is_sent(): void
    {
        // Given no user for this email
        $email = 'nobody@contoso.lk';
        Notification::fake();

        // When a reset is requested for it
        $this->forgotPassword(['email' => $email])->assertNoContent();

        // Then the response is the same 204, and no link or token is issued (enumeration-safe)
        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $email]);
    }

    #[Test]
    public function when_a_registered_email_requests_a_reset_then_a_reset_link_is_sent(): void
    {
        // Given a registered user
        $user = $this->aRegisteredUser();
        Notification::fake();

        // When they request a password reset
        $this->forgotPassword(['email' => $user->email])->assertNoContent();

        // Then a single-use reset link is emailed to them
        $this->assertResetLinkSentTo($user);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidEmailProvider(): array
    {
        return [
            'missing email' => [[], 'The email field is required.'],
            'empty email' => [['email' => ''], 'The email field is required.'],
            'malformed email' => [['email' => 'not-an-email'], 'The email field must be a valid email address.'],
            'array-shaped email' => [['email' => ['a', 'b']], 'The email field must be a valid email address.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function forgotPassword(array $payload): TestResponse
    {
        return $this->postJson('/api/v1/auth/forgot-password', $payload);
    }

    /**
     * Use up the client's per-IP allowance with $allowed passing requests for DISTINCT valid emails —
     * pinning the allowance at exactly $allowed while every per-email bucket stays at one hit, so only
     * the per-IP limit can refuse the request that follows. (Empty or repeated emails would share one
     * per-email bucket and be refused by that limit first.)
     */
    private function exhaustIpAllowance(int $allowed): void
    {
        for ($i = 0; $i < $allowed; $i++) {
            $this->forgotPassword(['email' => "guest{$i}@contoso.lk"])->assertNoContent();
        }
    }

    /**
     * Three passing requests for $email — its whole 3/15min allowance, pinned at exactly three.
     */
    private function exhaustEmailAllowance(string $email): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->forgotPassword(['email' => $email])->assertNoContent();
        }
    }

    /**
     * A queued mail-channel reset-link notification carrying a non-empty token was sent to $user;
     * its mail promises the 60-minute window and links the web reset-password page with the token +
     * the keyed email; and the broker issued a single-use token row for that email. (Expiry
     * ENFORCEMENT — rejecting an expired token — is covered by the reset increment, part 2.)
     */
    private function assertResetLinkSentTo(User $user): void
    {
        Notification::assertSentTo(
            $user,
            PasswordResetLinkNotification::class,
            function (PasswordResetLinkNotification $notification) use ($user): bool {
                $mail = $notification->toMail($user);

                return $notification->token !== ''
                    && $notification->via($user) === ['mail']
                    && $mail->subject === 'Reset your password'
                    && str_contains(
                        $mail->actionUrl,
                        '/reset-password?token='.$notification->token.'&email='.urlencode($user->getEmailForPasswordReset()),
                    )
                    && str_contains(implode(' ', $mail->outroLines), '60 minutes');
            },
        );
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }
}
