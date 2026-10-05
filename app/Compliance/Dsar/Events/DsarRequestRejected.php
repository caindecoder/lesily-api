<?php

namespace App\Compliance\Dsar\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class DsarRequestRejected extends ShouldBeStored
{
    public function __construct(
        public CarbonInterface $rejectedAt,
        public string $reason,
    ) {}
}
