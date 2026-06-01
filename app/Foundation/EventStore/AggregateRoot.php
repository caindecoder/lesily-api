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
}
