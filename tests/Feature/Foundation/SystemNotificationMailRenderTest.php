<?php

namespace Tests\Feature\Foundation;

use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $security->assertSeeInHtml("There was security activity on your Lesily account in Contoso. If it wasn't you, change your password now.", escape: false);
        $security->assertDontSeeInHtml('You have a new notice about your account in Contoso.');
        $notice->assertSeeInHtml('You have a new notice about your account in Contoso. Sign in to Lesily to read it.');
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

    #[Test]
    #[DataProvider('provideNotificationTypes')]
    public function when_any_notice_is_rendered_then_it_has_a_written_subject_and_body(NotificationType $type): void
    {
        // Given a notice of the type
        $mail = new SystemNotificationMail($type, 'Contoso');

        // When / Then — the type has a written subject and body, and no lang key shows through
        $this->assertWrittenCopyFor($mail, $type);
    }

    #[Test]
    #[DataProvider('provideDsarNoticeCopy')]
    public function when_a_data_export_notice_is_rendered_then_it_reads_its_own_subject_and_body(NotificationType $type, string $subject, string $body): void
    {
        // Given a data-export notice for Contoso
        $mail = new SystemNotificationMail($type, 'Contoso');

        // When / Then — its subject and body are this outcome's own words
        $mail->assertHasSubject($subject);
        $mail->assertSeeInHtml($body);
    }

    /**
     * @return array<string, array{NotificationType, string, string}>
     */
    public static function provideDsarNoticeCopy(): array
    {
        return [
            'ready' => [
                NotificationType::DsarExportReady,
                'A personal data export for Contoso is ready',
                'The personal data export requested in Contoso is ready. The person it is about can download it while signed in to Lesily, for 7 days.',
            ],
            'failed' => [
                NotificationType::DsarExportFailed,
                'A personal data export for Contoso could not be prepared',
                'The personal data export requested in Contoso could not be prepared. Please submit a new request.',
            ],
            'withdrawn' => [
                NotificationType::DsarRequestWithdrawn,
                'A personal data export request for Contoso was withdrawn',
                'The personal data export request in Contoso was withdrawn before it was ready. A new request can be made at any time.',
            ],
        ];
    }

    /**
     * @return array<string, array{NotificationType}>
     */
    public static function provideNotificationTypes(): array
    {
        return collect(NotificationType::cases())
            ->mapWithKeys(fn (NotificationType $type): array => [$type->value => [$type]])
            ->all();
    }

    protected function assertWrittenCopyFor(SystemNotificationMail $mail, NotificationType $type): void
    {
        $this->assertTrue(Lang::has("notifications.{$type->value}.subject", 'en', false));
        $this->assertTrue(Lang::has("notifications.{$type->value}.body", 'en', false));
        $mail->assertDontSeeInHtml('notifications.');
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
