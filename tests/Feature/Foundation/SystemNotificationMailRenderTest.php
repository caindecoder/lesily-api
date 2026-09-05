<?php

namespace Tests\Feature\Foundation;

use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Notifications\Mail\SystemNotificationMail;

/**
 * @testclass-carveout: mail-render — the class under test IS the behaviour: a
 * Mailable has no HTTP seam, so its rendering can only be proven directly.
 */
class SystemNotificationMailRenderTest extends FoundationTestCase
{
    #[Test]
    public function when_built_then_it_renders_from_the_markdown_template(): void
    {
        // Given
        $mail = new SystemNotificationMail(NotificationType::GeneralAccountNotice, 'Contoso');

        // When / Then
        $this->assertRendersFromMarkdownTemplate($mail);
    }

    #[Test]
    public function when_rendered_then_the_body_reflects_the_notification_type(): void
    {
        // Given
        $security = new SystemNotificationMail(NotificationType::AccountSecurityAlert, 'Contoso');
        $notice = new SystemNotificationMail(NotificationType::GeneralAccountNotice, 'Contoso');

        // When / Then
        $this->assertNotSame($security->render(), $notice->render());
    }

    #[Test]
    public function when_a_critical_notification_mail_is_rendered_then_its_subject_is_the_company_scoped_security_line(): void
    {
        // Given
        $mail = new SystemNotificationMail(NotificationType::AccountSecurityAlert, 'Contoso');

        // When / Then
        $mail->assertHasSubject('Security alert for Contoso');
    }

    #[Test]
    public function when_a_general_notification_mail_is_rendered_then_its_subject_is_the_company_scoped_notice_line(): void
    {
        // Given
        $mail = new SystemNotificationMail(NotificationType::GeneralAccountNotice, 'Contoso');

        // When / Then
        $mail->assertHasSubject('A notification from Contoso');
    }

    #[Test]
    public function when_a_password_changed_mail_is_rendered_then_its_subject_is_the_confirmation_line(): void
    {
        // Given
        $mail = new SystemNotificationMail(NotificationType::PasswordChanged, 'Contoso');

        // When / Then — the UC-1-016 postcondition subject (company-agnostic — no :company placeholder)
        $mail->assertHasSubject('Your password was changed');
    }

    #[Test]
    public function when_an_ownership_received_mail_is_rendered_then_its_subject_names_the_company_handed_over(): void
    {
        // Given
        $mail = new SystemNotificationMail(NotificationType::OwnershipReceived, 'Contoso');

        // When / Then — a missing lang entry would ship the literal translation
        // key as the subject (UC-1-028 new-owner notice)
        $mail->assertHasSubject('You are now the owner of Contoso');
    }

    /**
     * The blade uses the <x-mail::message> component, whose `mail` view namespace
     * is registered only by the markdown render path — so pinning `content()` to
     * the markdown template (not a plain view) guards the OQ-1-113 fix independent
     * of process state, then proves the template actually compiles.
     */
    protected function assertRendersFromMarkdownTemplate(SystemNotificationMail $mail): void
    {
        $this->assertSame('emails.system-notification', $mail->content()->markdown);
        $this->assertNull($mail->content()->view);
        $this->assertNotEmpty($mail->render());
    }
}
