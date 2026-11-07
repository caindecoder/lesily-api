<?php

namespace Tests\Feature\Foundation;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Spatie\EventSourcing\Projectionist;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Spatie\EventSourcing\StoredEvents\HandleStoredEventJob;

trait InteractsWithQueuedReactors
{
    protected function assertReactorDeferred(string $eventClass, string $notificationClass): void
    {
        Notification::assertSentTimes($notificationClass, 0);

        Queue::assertPushed(
            HandleStoredEventJob::class,
            fn (HandleStoredEventJob $job): bool => $job->storedEvent->event_class === $eventClass,
        );
    }

    protected function assertNoHandlersQueuedFor(string $eventClass): void
    {
        Queue::assertNotPushed(
            HandleStoredEventJob::class,
            fn (HandleStoredEventJob $job): bool => $job->storedEvent->event_class === $eventClass,
        );
    }

    protected function queuedHandlerCountFor(string $eventClass): int
    {
        return $this->queuedHandlersFor($eventClass)->count();
    }

    /**
     * @return Collection<int, HandleStoredEventJob>
     */
    protected function queuedHandlersFor(string $eventClass): Collection
    {
        $queue = Queue::getFacadeRoot();

        if (! $queue instanceof QueueFake) {
            self::fail('The queued handlers can only be read with Queue::fake() armed first.');
        }

        return collect($queue->pushed(HandleStoredEventJob::class))
            ->filter(fn (HandleStoredEventJob $job): bool => $job->storedEvent->event_class === $eventClass)
            ->values();
    }

    protected function runQueuedHandlersFor(string $eventClass): void
    {
        $jobs = $this->queuedHandlersFor($eventClass);

        if ($jobs->isEmpty()) {
            self::fail("No captured HandleStoredEventJob matches {$eventClass} — nothing to drain.");
        }

        $jobs->each(fn (HandleStoredEventJob $job) => $job->handle(app(Projectionist::class)));
    }
}
