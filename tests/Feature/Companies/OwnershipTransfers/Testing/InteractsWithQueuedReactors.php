<?php

namespace Tests\Feature\Companies\OwnershipTransfers\Testing;

use Illuminate\Support\Facades\Queue;
use Spatie\EventSourcing\Projectionist;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Spatie\EventSourcing\StoredEvents\HandleStoredEventJob;

/**
 * Queue-seam helpers for the module's queued reactors (Task R2).
 *
 * The seam is the vendor dispatch chain: StoredEvent::handle() runs every
 * non-queued handler inline, then dispatches ONE HandleStoredEventJob per
 * stored event iff any handler implements ShouldQueue. Queue::fake()
 * intercepts that dispatch, so a queued reactor's side effect demonstrably
 * did NOT run inline (the WelcomeEmailTest precedent, verified against
 * vendor source at the R2 pre-flight).
 */
trait InteractsWithQueuedReactors
{
    /**
     * The $eventClass's handler job was pushed and $notificationClass was NOT
     * sent inline — the deferred posture, grouped (§2.2 Rule 2).
     *
     * @param  class-string  $eventClass
     * @param  class-string  $notificationClass
     */
    protected function assertReactorDeferred(string $eventClass, string $notificationClass): void
    {
        Notification::assertSentTimes($notificationClass, 0);
        Queue::assertPushed(
            HandleStoredEventJob::class,
            fn (HandleStoredEventJob $job): bool => $job->storedEvent->event_class === $eventClass,
        );
    }

    /**
     * Execute every captured handler job for $eventClass the way a worker
     * would — outside any transaction, after the write committed. This is the
     * delivery-time simulation the staleness-guard and reactor-failure rows
     * are built on.
     *
     * @param  class-string  $eventClass
     */
    protected function runQueuedHandlersFor(string $eventClass): void
    {
        $queue = Queue::getFacadeRoot();

        if (! $queue instanceof QueueFake) {
            self::fail('Queue::fake() must be armed before draining queued handler jobs.');
        }

        $queue->pushed(HandleStoredEventJob::class)
            ->filter(fn (HandleStoredEventJob $job): bool => $job->storedEvent->event_class === $eventClass)
            ->each(fn (HandleStoredEventJob $job) => $job->handle(app(Projectionist::class)));
    }
}
