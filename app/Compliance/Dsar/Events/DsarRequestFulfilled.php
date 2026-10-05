<?php

namespace App\Compliance\Dsar\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class DsarRequestFulfilled extends ShouldBeStored
{
    public function __construct(
        public CarbonInterface $completedAt,
        public string $filePath,
        public CarbonInterface $downloadExpiresAt,
    ) {}
}
