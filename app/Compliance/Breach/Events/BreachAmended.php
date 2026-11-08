<?php

namespace App\Compliance\Breach\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BreachAmended extends ShouldBeStored
{
    /**
     * @param  list<array{field: string, companyId: ?string, old: mixed, new: mixed}>  $changes
     */
    public function __construct(
        public string $breachId,
        public CarbonInterface $amendedAt,
        public string $amendedByUserId,
        public string $reason,
        public array $changes,
    ) {}
}
