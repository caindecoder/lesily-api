<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use RuntimeException;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Notifications\Mail\SystemNotificationMail;
use App\Foundation\Notifications\Entities\NotificationPreference;
use App\Foundation\Notifications\Notifications\SystemNotification;

class ReceiveEmailNotificationTest extends FoundationTestCase
{
    use RefreshDatabase;

    #[Test]
    public function when_a_notification_is_dispatched_then_the_in_app_record_is_created_and_email_is_queued_independently(): void
    {
        Mail::fake();
        $recipient = User::factory()->create();

        $recipient->notify(new SystemNotification(NotificationType::AccountSecurityAlert, 'Contoso'));

        $this->assertInAppCreatedAndEmailQueuedIndependently($recipient);
    }

    #[Test]
    public function when_email_delivery_fails_then_the_failure_is_logged_without_alerting_the_user(): void
    {
        Exceptions::fake();
        $mail = new SystemNotificationMail(NotificationType::AccountSecurityAlert, 'Contoso');

        $mail->failed(new RuntimeException('Mail server unavailable'));

        $this->assertFailureLoggedWithRetryConfig($mail);
    }

    #[Test]
    public function when_user_opted_out_of_a_non_critical_notification_then_email_is_skipped_but_in_app_is_created(): void
    {
        Mail::fake();
        $recipient = User::factory()->create();
        $this->optOutOfMail($recipient, NotificationType::GeneralAccountNotice);

        $recipient->notify(new SystemNotification(NotificationType::GeneralAccountNotice, 'Contoso'));

        $this->assertOnlyInAppDelivered($recipient);
    }

    #[Test]
    public function when_notification_is_critical_then_user_opt_out_is_ignored_and_email_is_sent(): void
    {
        Mail::fake();
        $recipient = User::factory()->create();
        $this->optOutOfMail($recipient, NotificationType::AccountSecurityAlert);

        $recipient->notify(new SystemNotification(NotificationType::AccountSecurityAlert, 'Contoso'));

        $this->assertEmailQueuedAndInAppCreated($recipient);
    }

    protected function assertEmailQueuedAndInAppCreated(User $recipient): void
    {
        Mail::assertQueued(SystemNotificationMail::class);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $recipient->getKey()]);
    }

    protected function optOutOfMail(User $recipient, NotificationType $type): void
    {
        NotificationPreference::factory()->mailDisabled($recipient, $type)->create();
    }

    protected function assertOnlyInAppDelivered(User $recipient): void
    {
        Mail::assertNotQueued(SystemNotificationMail::class);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $recipient->getKey()]);
    }

    protected function assertInAppCreatedAndEmailQueuedIndependently(User $recipient): void
    {
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $recipient->getKey(),
            'type' => SystemNotification::class,
        ]);
        Mail::assertQueued(SystemNotificationMail::class);
    }

    protected function assertFailureLoggedWithRetryConfig(SystemNotificationMail $mail): void
    {
        Exceptions::assertReported(RuntimeException::class);
        $this->assertSame(3, $mail->tries);
        $this->assertSame([1, 5, 15], $mail->backoff);
    }
}
