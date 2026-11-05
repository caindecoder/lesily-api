<?php

namespace App\Compliance\Breach\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BreachRecorded extends ShouldBeStored
{
    /**
     * @param  list<array{companyId: string, dataTypes: list<string>, estimatedCount: ?int}>  $companies
     */
    public function __construct(
        public string $breachId,
        public CarbonInterface $recordedAt,
        public string $recordedByUserId,
        public string $description,
        public string $likelyConsequences,
        public string $measures,
        public CarbonInterface $dateDiscovered,
        public string $severity,
        public CarbonInterface $deadline,
        public array $companies,
    ) {}
}
