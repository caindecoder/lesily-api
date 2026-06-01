<?php

namespace Tests\Feature\Foundation\EventStore;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Foundation\FoundationTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\EventStore\Fixtures\FixtureAggregate;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use Tests\Feature\Foundation\EventStore\Fixtures\FixtureThingHappened;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEventCollection;

class EventStoreFoundationTest extends FoundationTestCase
{
    use RefreshDatabase;

    // Sad / degenerate — the simplest thing that can be stored: a tenant-less event.
    #[Test]
    public function when_an_aggregate_records_an_event_without_a_company_then_it_is_stored_with_no_company_scope(): void
    {
        // Given a fixture aggregate that records an event not bound to any company
        $aggregateUuid = (string) Str::uuid();

        // When it records and persists
        FixtureAggregate::retrieve($aggregateUuid)->recordThing()->persist();

        // Then the event is stored but carries no company scope
        $stored = LesilyStoredEvent::query()->get();

        $this->assertEventStoredForCompany($stored, FixtureThingHappened::class, null);
    }

    // Edge — the tenant boundary: a company scope must exclude other companies' events.
    #[Test]
    public function when_two_companies_have_events_then_the_company_scope_returns_only_the_matching_companys_events(): void
    {
        // Given an event recorded for each of two different companies
        $companyA = '11111111-1111-4111-8111-111111111111';
        $companyB = '22222222-2222-4222-8222-222222222222';
        FixtureAggregate::retrieve((string) Str::uuid())->recordThingFor($companyA)->persist();
        FixtureAggregate::retrieve((string) Str::uuid())->recordThingFor($companyB)->persist();

        // When the store is queried for company A only
        $stored = LesilyStoredEvent::query()->whereForCompany($companyA)->get();

        // Then company B's event is excluded
        $this->assertEventStoredForCompany($stored, FixtureThingHappened::class, $companyA);
    }

    // Happy — the headline behaviour: a company's event is stored and retrievable in its scope.
    #[Test]
    public function when_an_aggregate_records_an_event_for_a_company_then_the_event_is_stored_in_that_companys_scope(): void
    {
        // Given a fixture aggregate that belongs to a company
        $companyId = '11111111-1111-4111-8111-111111111111';
        $aggregateUuid = (string) Str::uuid();

        // When it records an event and persists
        FixtureAggregate::retrieve($aggregateUuid)->recordThingFor($companyId)->persist();

        // Then exactly that event is retrievable through the company-scoped store
        $stored = LesilyStoredEvent::query()->whereForCompany($companyId)->get();

        $this->assertEventStoredForCompany($stored, FixtureThingHappened::class, $companyId);
    }

    // Happy — the event-sourcing loop closes: state is rebuilt by replaying stored events.
    #[Test]
    public function when_an_aggregate_is_retrieved_then_its_state_is_rebuilt_from_the_stored_events(): void
    {
        // Given an aggregate that recorded and persisted an event
        $aggregateUuid = (string) Str::uuid();
        FixtureAggregate::retrieve($aggregateUuid)->recordThing()->persist();

        // When a fresh instance is retrieved, forcing reconstitution from the event store
        $rebuilt = FixtureAggregate::retrieve($aggregateUuid);

        // Then its state reflects the replayed event
        $this->assertSame(1, $rebuilt->thingCount());
    }

    protected function assertEventStoredForCompany(EloquentStoredEventCollection $stored, string $expectedEventClass, ?string $expectedCompanyId): void
    {
        $this->assertCount(1, $stored);

        $event = $stored->firstOrFail();
        $this->assertSame($expectedEventClass, $event->event_class);
        $this->assertSame($expectedCompanyId, $event->company_id);
    }
}
