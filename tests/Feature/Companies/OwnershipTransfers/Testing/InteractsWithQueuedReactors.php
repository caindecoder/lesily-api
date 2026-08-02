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
     * Scope note (review, all four passes): the job-pushed prong is
     * EVENT-scoped, not reactor-scoped — on a multi-reactor event any queued
     * sibling satisfies it. Per-reactor teeth come from the not-sent-inline
     * prong PAIRED with the class's pre-existing positive delivery row; a
     * deferred row whose positive twin is deleted degrades to near-vacuous
     * silently.
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
     * Execute every captured handler job for $eventClass — the delivery-time
     * simulation the staleness-guard and reactor-failure rows are built on.
     *
     * Its honest scope, stated (review, three passes): the drain runs after
     * the INTERACTOR's transaction closed and the request returned — under
     * RefreshDatabase the test wrapper transaction is still open, so
     * "committed" is savepoint release — and it replays the same in-memory
     * job object, so a real worker's serialize/deserialize round trip is NOT
     * exercised here. Production deferral itself rests on the database
     * connection's `after_commit => true`, pinned app-level by
     * EmailVerificationTest's after-commit contract row.
     *
     * @param  class-string  $eventClass
     */
    protected function runQueuedHandlersFor(string $eventClass): void
    {
        $queue = Queue::getFacadeRoot();

        if (! $queue instanceof QueueFake) {
            self::fail('Queue::fake() must be armed before draining queued handler jobs.');
        }

        $jobs = $queue->pushed(HandleStoredEventJob::class)
            ->filter(fn (HandleStoredEventJob $job): bool => $job->storedEvent->event_class === $eventClass);

        if ($jobs->isEmpty()) {
            self::fail("No captured HandleStoredEventJob matches {$eventClass} — nothing to drain (find-nothing guard).");
        }

        $jobs->each(fn (HandleStoredEventJob $job) => $job->handle(app(Projectionist::class)));
    }
}
