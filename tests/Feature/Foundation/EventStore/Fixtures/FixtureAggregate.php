<?php

namespace Tests\Feature\Foundation\EventStore\Fixtures;

use App\Foundation\EventStore\AggregateRoot;

/**
 * Test-only aggregate proving the event-store foundation works end-to-end,
 * without coupling to the not-yet-built Company aggregate (increment #02).
 */
class FixtureAggregate extends AggregateRoot
{
    protected int $thingCount = 0;

    public function recordThing(): static
    {
        return $this->recordThat(new FixtureThingHappened);
    }

    public function recordThingFor(string $companyId): static
    {
        return $this->forCompany($companyId)
            ->recordThat(new FixtureThingHappened);
    }

    public function thingCount(): int
    {
        return $this->thingCount;
    }

    protected function applyFixtureThingHappened(FixtureThingHappened $event): void
    {
        $this->thingCount++;
    }
}
