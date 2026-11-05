<?php

namespace App\Compliance\Breach\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BreachCompanyNotified extends ShouldBeStored
{
    /**
     * @param  array<string, string>  $part  the company's own part of the incident, as sent
     */
    public function __construct(
        public string $breachId,
        public string $companyId,
        public CarbonInterface $notifiedAt,
        public string $notifiedByUserId,
        public array $part,
    ) {}
}
