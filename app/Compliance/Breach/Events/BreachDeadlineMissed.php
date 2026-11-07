<?php

namespace App\Compliance\Breach\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BreachDeadlineMissed extends ShouldBeStored
{
    public function __construct(
        public string $breachId,
        public CarbonInterface $missedAt,
    ) {}
}
