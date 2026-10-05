<?php

namespace App\Compliance\Dsar\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class DsarRequestCancelled extends ShouldBeStored
{
    public function __construct(
        public CarbonInterface $cancelledAt,
        public string $cancelledByUserId,
    ) {}
}
