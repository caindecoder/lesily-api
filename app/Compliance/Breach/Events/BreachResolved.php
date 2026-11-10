<?php

namespace App\Compliance\Breach\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BreachResolved extends ShouldBeStored
{
    public function __construct(
        public string $breachId,
        public CarbonInterface $resolvedAt,
        public string $resolvedByUserId,
        public string $rootCause,
        public string $remediationSteps,
        public string $summary,
    ) {}
}
