<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use RuntimeException;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Notifications\Mail\SystemNotificationMail;
use App\Foundation\Notifications\Entities\NotificationPreference;
use App\Foundation\Notifications\Notifications\SystemNotification;

class ReceiveEmailNotificationTest extends FoundationTestCase
{
    #[Test]
    public function when_email_delivery_fails_then_the_failure_is_logged_without_alerting_the_user(): void
    {
        Exceptions::fake();
        $mail = new SystemNotificationMail(NotificationType::AccountSecurityAlert, 'Contoso');

        $mail->failed(new RuntimeException('Mail server unavailable'));

        $this->assertFailureLoggedWithRetryConfig($mail);
    }

    #[Test]
    public function when_a_notice_concerns_the_whole_account_then_it_is_stored_without_a_company(): void
    {
        // Given a user
        Mail::fake();
        $recipient = User::factory()->create();

        // When an account-level notice is raised for them
        $recipient->notify(new SystemNotification(NotificationType::PasswordChanged, 'Contoso', null));

        // Then its in-app record belongs to no company
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $recipient->getKey(),
            'company_id' => null,
        ]);
    }

    #[Test]
    public function when_user_opted_out_of_a_non_critical_notification_then_email_is_skipped_but_in_app_is_created(): void
    {
        Mail::fake();
        $recipient = User::factory()->create();
        $this->optOutOfMail($recipient, NotificationType::GeneralAccountNotice);

        $recipient->notify(new SystemNotification(NotificationType::GeneralAccountNotice, 'Contoso', null));

        $this->assertOnlyInAppDelivered($recipient);
    }

    #[Test]
    public function when_notification_is_critical_then_user_opt_out_is_ignored_and_email_is_sent(): void
    {
        Mail::fake();
        $recipient = User::factory()->create();
        $this->optOutOfMail($recipient, NotificationType::AccountSecurityAlert);

        $recipient->notify(new SystemNotification(NotificationType::AccountSecurityAlert, 'Contoso', null));

        $this->assertEmailQueuedAndInAppCreated($recipient);
    }

    #[Test]
    #[DataProvider('preferredLocaleProvider')]
    public function when_recipient_has_a_preferred_locale_then_the_email_is_rendered_in_that_locale(string $locale): void
    {
        Mail::fake();
        $recipient = User::factory()->locale($locale)->create();

        $recipient->notify(new SystemNotification(NotificationType::AccountSecurityAlert, 'Contoso', null));

        $this->assertEmailRenderedInLocale($locale);
    }

    #[Test]
    public function when_the_email_is_built_then_its_subject_is_the_localised_template_with_the_company_name(): void
    {
        $mail = new SystemNotificationMail(NotificationType::AccountSecurityAlert, 'Contoso');

        $this->assertSame('Security alert for Contoso', $mail->envelope()->subject);
    }

    #[Test]
    public function when_notification_is_dispatched_then_its_content_is_scoped_to_the_recipient_company(): void
    {
        Mail::fake();
        $recipientCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $recipient = User::factory()->create();

        $recipient->notify(new SystemNotification(NotificationType::AccountSecurityAlert, $recipientCompany->name, $recipientCompany->id));

        $this->assertContentScopedToCompany($recipient, $recipientCompany, $otherCompany);
    }

    #[Test]
    public function when_a_notification_is_dispatched_then_the_in_app_record_is_created_and_email_is_queued_independently(): void
    {
        Mail::fake();
        $recipient = User::factory()->create();

        $recipient->notify(new SystemNotification(NotificationType::AccountSecurityAlert, 'Contoso', null));

        $this->assertInAppCreatedAndEmailQueuedIndependently($recipient);
    }

    #[Test]
    public function when_a_notification_is_raised_for_a_user_then_an_email_and_an_in_app_notification_are_delivered(): void
    {
        Mail::fake();
        $recipient = User::factory()->create();

        $recipient->notify(new SystemNotification(NotificationType::AccountSecurityAlert, 'Contoso', null));

        $this->assertDeliveredAcrossBothChannels($recipient);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function preferredLocaleProvider(): array
    {
        return [
            'Sinhala' => ['si'],
            'Tamil' => ['ta'],
            'English' => ['en'],
        ];
    }

    protected function optOutOfMail(User $recipient, NotificationType $type): void
    {
        NotificationPreference::factory()->mailDisabled($recipient, $type)->create();
    }

    protected function assertFailureLoggedWithRetryConfig(SystemNotificationMail $mail): void
    {
        Exceptions::assertReported(RuntimeException::class);
        $this->assertSame(3, $mail->tries);
        $this->assertSame([1, 5, 15], $mail->backoff);
    }

    protected function assertOnlyInAppDelivered(User $recipient): void
    {
        Mail::assertNotQueued(SystemNotificationMail::class);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $recipient->getKey()]);
    }

    protected function assertEmailQueuedAndInAppCreated(User $recipient): void
    {
        Mail::assertQueued(SystemNotificationMail::class);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $recipient->getKey()]);
    }

    protected function assertEmailRenderedInLocale(string $expected): void
    {
        Mail::assertQueued(
            SystemNotificationMail::class,
            fn (SystemNotificationMail $mail): bool => $mail->locale === $expected,
        );
    }

    protected function assertContentScopedToCompany(User $recipient, Company $recipientCompany, Company $otherCompany): void
    {
        $notification = $recipient->notifications()->sole();
        $this->assertSame($recipientCompany->id, $notification->company_id);
        $this->assertSame($recipientCompany->name, $notification->data['company_name']);
        $this->assertNotSame($otherCompany->name, $notification->data['company_name']);
        $this->assertSame(NotificationType::AccountSecurityAlert->value, $notification->data['notification_type']);
    }

    protected function assertInAppCreatedAndEmailQueuedIndependently(User $recipient): void
    {
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $recipient->getKey(),
            'type' => SystemNotification::class,
        ]);
        Mail::assertQueued(SystemNotificationMail::class);
    }

    protected function assertDeliveredAcrossBothChannels(User $recipient): void
    {
        Mail::assertQueued(
            SystemNotificationMail::class,
            fn (SystemNotificationMail $mail): bool => $mail->hasTo($recipient->email)
                && $mail->envelope()->replyTo !== [],
        );
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $recipient->getKey()]);
    }
}
