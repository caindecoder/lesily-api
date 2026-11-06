<?php

namespace App\Compliance\Breach\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BreachFilingRequested extends ShouldBeStored
{
    public function __construct(
        public string $breachId,
        public string $companyId,
        public CarbonInterface $requestedAt,
        public string $requestedByUserId,
    ) {}
}
