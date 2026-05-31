<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Notifications\Mail\SystemNotificationMail;
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

    protected function assertInAppCreatedAndEmailQueuedIndependently(User $recipient): void
    {
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $recipient->getKey(),
            'type' => SystemNotification::class,
        ]);
        Mail::assertQueued(SystemNotificationMail::class);
    }
}
