<?php

namespace Tests\Feature\Foundation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Notifications\Mail\SystemNotificationMail;

class SystemNotificationMailRenderTest extends FoundationTestCase
{
    #[Test]
    #[DataProvider('supportedLocaleProvider')]
    public function when_rendered_in_each_supported_locale_then_the_markdown_email_renders(string $locale): void
    {
        // Given
        $mail = (new SystemNotificationMail(NotificationType::GeneralAccountNotice, 'Contoso'))->locale($locale);

        // When / Then
        $this->assertRendersInLocale($mail);
    }

    #[Test]
    public function when_a_critical_notification_mail_is_rendered_then_its_subject_is_the_company_scoped_security_line(): void
    {
        // Given
        $mail = new SystemNotificationMail(NotificationType::AccountSecurityAlert, 'Contoso');

        // When / Then
        $this->assertRendersWithSubject($mail, 'Security alert for Contoso');
    }

    #[Test]
    public function when_a_general_notification_mail_is_rendered_then_its_subject_is_the_company_scoped_notice_line(): void
    {
        // Given
        $mail = new SystemNotificationMail(NotificationType::GeneralAccountNotice, 'Contoso');

        // When / Then
        $this->assertRendersWithSubject($mail, 'A notification from Contoso');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function supportedLocaleProvider(): array
    {
        return [
            'Sinhala' => ['si'],
            'Tamil' => ['ta'],
            'English' => ['en'],
        ];
    }

    protected function assertRendersInLocale(SystemNotificationMail $mail): void
    {
        $rendered = $mail->render();

        $this->assertNotEmpty($rendered);
        $this->assertStringContainsString(config('app.name'), $rendered);
    }

    protected function assertRendersWithSubject(SystemNotificationMail $mail, string $expectedSubject): void
    {
        $mail->assertHasSubject($expectedSubject);
        $this->assertStringContainsString(config('app.name'), $mail->render());
    }
}
