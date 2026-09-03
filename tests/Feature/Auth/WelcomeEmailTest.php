<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use App\Foundation\Notifications\Enums\NotificationType;
use Spatie\EventSourcing\StoredEvents\HandleStoredEventJob;
use App\Foundation\Notifications\Notifications\SystemNotification;

class WelcomeEmailTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The welcome email is a boundary side effect — spy it, never send.
        Notification::fake();
    }

    // Side-effect (deferral) — the welcome notification is dispatched as a queued
    // after-commit job, not sent inline; that deferral is what lets a rolled-back
    // registration send nothing (after_commit discards the job). The async
    // connection's after_commit flag is pinned once, in EmailVerificationTest.
    #[Test]
    public function when_an_owner_registers_then_the_welcome_notification_is_queued_not_sent_inline(): void
    {
        // Given the notification handler can only run off the queue
        Queue::fake();
        $this->seedRbacCatalog();

        // When a guest registers as owner
        $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload());

        // Then the handler is deferred to the queue and nothing is sent inline
        Queue::assertPushed(HandleStoredEventJob::class);
        Notification::assertNothingSent();
    }

    // Happy — registering as owner sends the welcome notification (Pattern D).
    #[Test]
    public function when_an_owner_registers_then_a_welcome_notification_is_sent(): void
    {
        // Given the RBAC catalog the owner pivot resolves against
        $this->seedRbacCatalog();

        // When a guest registers as owner
        $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload());

        // Then a welcome notification is sent to the new owner for their company
        $this->assertWelcomeNotificationSentTo('nimal@contoso.lk', 'Contoso');
    }

    protected function assertWelcomeNotificationSentTo(string $email, string $companyName): void
    {
        $owner = User::query()->where('email', $email)->sole();

        Notification::assertSentTo(
            $owner,
            SystemNotification::class,
            fn (SystemNotification $notification): bool => $notification->type === NotificationType::GeneralAccountNotice
                && $notification->companyName === $companyName,
        );
    }
}
