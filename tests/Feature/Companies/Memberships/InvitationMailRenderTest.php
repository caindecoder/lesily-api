<?php

namespace Tests\Feature\Companies\Memberships;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
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
