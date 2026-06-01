<?php

namespace App\Foundation\EventStore;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot as BaseAggregateRoot;

/**
 * Project base aggregate. Wraps spatie's AggregateRoot (the logged, bounded
 * deviation) so domain aggregates never extend the framework class directly,
 * and tags every recorded event with the tenant (company) it belongs to —
 * the hook that makes the event store queryable per company for PDPA walks.
 */
class AggregateRoot extends BaseAggregateRoot
{
    protected ?string $companyId = null;

    public function forCompany(string $companyId): static
    {
        $this->companyId = $companyId;

        return $this;
    }

    public function recordThat(ShouldBeStored $domainEvent): static
    {
        if ($this->companyId !== null) {
            $domainEvent->setMetaData($domainEvent->metaData() + ['company_id' => $this->companyId]);
        }

        return parent::recordThat($domainEvent);
    }

    protected function apply(ShouldBeStored $event): void
    {
        // Restore tenant identity from the event's metadata so a reconstituted
        // aggregate keeps scoping the events it goes on to record.
        // NOTE: relies on the genesis (company-bearing) event being replayed.
        // When snapshots are enabled, capture company_id in snapshot state too —
        // protected props are excluded from spatie getState() and pre-snapshot
        // events are not replayed (see slice plan: snapshot future-guard).
        $companyId = data_get($event->metaData(), 'company_id');
        if ($companyId !== null) {
            $this->companyId = $companyId;
        }

        parent::apply($event);
    }
}
