<?php

namespace Tests\Feature\Foundation;

use Illuminate\Support\Facades\Queue;
use Spatie\EventSourcing\Projectionist;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Spatie\EventSourcing\StoredEvents\HandleStoredEventJob;

trait InteractsWithQueuedReactors
{
    protected function runQueuedHandlersFor(string $eventClass): void
    {
        $queue = Queue::getFacadeRoot();

        if (! $queue instanceof QueueFake) {
            self::fail('runQueuedHandlersFor() requires Queue::fake() to be armed first.');
        }

        $jobs = collect($queue->pushed(HandleStoredEventJob::class))
            ->filter(fn (HandleStoredEventJob $job): bool => $job->storedEvent->event_class === $eventClass);

        if ($jobs->isEmpty()) {
            self::fail("No captured HandleStoredEventJob matches {$eventClass} — nothing to drain.");
        }

        $jobs->each(fn (HandleStoredEventJob $job) => $job->handle(app(Projectionist::class)));
    }
}
