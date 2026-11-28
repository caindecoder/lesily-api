<?php

namespace Tests\Concerns;

use Throwable;
use RuntimeException;
use Spatie\EventSourcing\Projectionist;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * A projector failure induced at the event-dispatch seam. Consumed by the
 * companies and auth bases (no shared module base exists below the root —
 * §2.10).
 */
trait InducesProjectorFailures
{
    /**
     * Register a projector that throws $throwable when $eventClass is dispatched
     * (weight 1000 — AFTER the real projectors, so their writes happen and the
     * rollback is proven against them). Register AFTER the arrange: the handler
     * matches every stored event and filters by class in the body.
     */
    protected function induceProjectorFailureFor(string $eventClass, ?Throwable $throwable = null): void
    {
        $projector = new class extends Projector
        {
            public string $targetEventClass = '';

            public ?Throwable $throwable = null;

            public int $weight = 1000;

            public function onStoredEvent(ShouldBeStored $shouldBeStored): void
            {
                if ($shouldBeStored::class === $this->targetEventClass) {
                    throw $this->throwable ?? new RuntimeException('Induced projector failure');
                }
            }
        };
        $projector->targetEventClass = $eventClass;
        $projector->throwable = $throwable;

        // The projectionist re-resolves handlers from the container by class
        // name on every call; without this instance binding the dispatch would
        // get a FRESH copy whose target class is '' and never throw.
        app()->instance($projector::class, $projector);
        app(Projectionist::class)->addProjector($projector);
    }
}
