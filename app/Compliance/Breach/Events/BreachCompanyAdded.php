<?php

namespace App\Compliance\Breach\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BreachCompanyAdded extends ShouldBeStored
{
    /**
     * @param  list<string>  $dataTypes
     */
    public function __construct(
        public string $breachId,
        public string $companyId,
        public array $dataTypes,
        public ?int $estimatedCount,
        public string $reason,
        public CarbonInterface $addedAt,
        public string $addedByUserId,
    ) {}
}
