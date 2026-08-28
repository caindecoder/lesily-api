<?php

namespace Tests\Feature\Companies\Memberships;

use RuntimeException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Mail\Mailables\Address;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Exceptions;
use App\Companies\Memberships\Mail\InvitationMail;

/**
 * Mail::fake() short-circuits rendering, so the endpoint tests never compile the
 * invitation template — an undefined variable or broken component there would
 * only surface as a failed queue job in production. These render tests close
 * that blind spot (SystemNotificationMailRenderTest precedent, OQ-1-113 family).
 *
 * @testclass-carveout: mail-render — the class under test IS the behaviour: a
 * Mailable has no HTTP seam, so its rendering can only be proven directly.
 */
class InvitationMailRenderTest extends MembershipsTestCase
{
    #[Test]
    public function when_the_invite_email_delivery_fails_then_the_failure_is_logged_without_alerting_the_inviter(): void
    {
        // Given
        Exceptions::fake();
        $mail = $this->anInvitationMail();

        // When the queued mail exhausts its retries
        $mail->failed(new RuntimeException('Mail server unavailable'));

        // Then the failure is reported for ops with the retry config intact — the
        // inviter is never alerted (the pending invitation stays resendable, UC-1-021)
        Exceptions::assertReported(RuntimeException::class);
        $this->assertSame(3, $mail->tries);
        $this->assertSame([1, 5, 15], $mail->backoff);
    }

    #[Test]
    public function when_built_then_it_renders_from_the_markdown_template(): void
    {
        // Given
        $mail = $this->anInvitationMail();

        // When / Then — pin the markdown path (the <x-mail::message> component only
        // resolves through it), then prove the template actually compiles
        $this->assertSame('emails.invitation', $mail->content()->markdown);
        $this->assertNull($mail->content()->view);
        $this->assertNotEmpty($mail->render());
    }

    #[Test]
    public function when_rendered_then_the_body_carries_the_accept_link_company_and_expiry(): void
    {
        // Given
        $mail = $this->anInvitationMail();

        // When
        $rendered = $mail->render();

        // Then
        $this->assertAcceptLinkRendered($mail, $rendered);
        $this->assertStringContainsString('Contoso', $rendered);
        $this->assertStringContainsString($mail->expiresAt->toFormattedDateString(), $rendered);
    }

    #[Test]
    public function when_built_then_its_subject_names_the_inviting_company(): void
    {
        // Given
        $mail = $this->anInvitationMail();

        // When / Then
        $mail->assertHasSubject('You have been invited to join Contoso on Lesily');
    }

    #[Test]
    public function when_built_then_replies_route_to_the_platform_sender(): void
    {
        // Given
        $mail = $this->anInvitationMail();

        // When
        $envelope = $mail->envelope();

        // Then
        $this->assertRepliesRouteToPlatformSender($envelope);
    }

    /**
     * The accept link points at the web app's invitation page and carries the
     * single-use plaintext token into the rendered body.
     */
    protected function assertAcceptLinkRendered(InvitationMail $mail, string $rendered): void
    {
        $this->assertStringStartsWith(config('client.invitation_url'), $mail->acceptUrl());
        $this->assertStringContainsString($mail->plaintextToken, $rendered);
    }

    /**
     * A reply to the invitation reaches the platform sender: exactly one
     * reply-to Address, and it is the configured from-address.
     */
    protected function assertRepliesRouteToPlatformSender(Envelope $envelope): void
    {
        $this->assertCount(1, $envelope->replyTo);
        $this->assertInstanceOf(Address::class, $envelope->replyTo[0]);
        $this->assertSame(config('mail.from.address'), $envelope->replyTo[0]->address);
    }

    private function anInvitationMail(): InvitationMail
    {
        return new InvitationMail(
            companyName: 'Contoso',
            email: 'invitee@contoso.lk',
            plaintextToken: Str::random(40),
            expiresAt: CarbonImmutable::now()->addDays(7),
        );
    }
}
