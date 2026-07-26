<?php

namespace Tests\Feature\Companies\Memberships;

use RuntimeException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Exceptions;
use App\Companies\Memberships\Mail\InvitationMail;

/**
 * Mail::fake() short-circuits rendering, so the endpoint tests never compile the
 * invitation template — an undefined variable or broken component there would
 * only surface as a failed queue job in production. These render tests close
 * that blind spot (SystemNotificationMailRenderTest precedent, OQ-1-113 family).
 */
class InvitationMailRenderTest extends MembershipsTestCase
{
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
        $this->assertStringContainsString($mail->plaintextToken, $rendered);
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

    /**
     * The inviting company's name is tenant-chosen and lands in a markdown view,
     * so CommonMark would read it as MARKUP — turning an invitation into a
     * Lesily-branded email carrying a link the tenant picked, next to the genuine
     * accept button, for a recipient who by definition does not yet know what a
     * real Lesily invitation looks like.
     *
     * Held by the global `Markdown::withSecuredEncoding()` in AppServiceProvider
     * (proven by revert-check: drop that line and this REDs). Pinned HERE as well
     * as on the ownership-transfer path because this view was already live before
     * that call existed — a global default with one consumer's test behind it is
     * one refactor away from being silently dropped for the others.
     */
    #[Test]
    public function when_the_inviting_company_name_carries_markdown_then_the_email_renders_it_as_text(): void
    {
        // Given an invitation from a company that has named itself a link
        $mail = $this->anInvitationMail('Acme [Claim your account](https://not-lesily.example)');

        // When the invitation is rendered
        $rendered = $mail->render();

        // Then the tenant's markup is inert beside the real accept button
        $this->assertStringNotContainsString('href="https://not-lesily.example"', $rendered);
        $this->assertStringContainsString($mail->plaintextToken, $rendered);
    }

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

    private function anInvitationMail(string $companyName = 'Contoso'): InvitationMail
    {
        return new InvitationMail(
            companyName: $companyName,
            email: 'invitee@contoso.lk',
            plaintextToken: Str::random(40),
            expiresAt: CarbonImmutable::now()->addDays(7),
        );
    }
}
