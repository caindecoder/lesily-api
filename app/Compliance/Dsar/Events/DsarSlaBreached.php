<?php

namespace App\Compliance\Dsar\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class DsarSlaBreached extends ShouldBeStored
{
    public function __construct(
        public CarbonInterface $breachedAt,
    ) {}
}
