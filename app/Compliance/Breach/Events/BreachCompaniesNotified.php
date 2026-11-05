<?php

namespace App\Compliance\Breach\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BreachCompaniesNotified extends ShouldBeStored
{
    public function __construct(
        public string $breachId,
        public CarbonInterface $notifiedAt,
        public string $notifiedByUserId,
    ) {}
}
