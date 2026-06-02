<?php

namespace App\Companies\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * trialEndsAt is typed CarbonInterface (not CarbonImmutable) because spatie's
 * CarbonNormalizer denormalises stored events back to a mutable Carbon on replay;
 * the aggregate records an immutable value (CarbonImmutable::now()->addDays(...)).
 */
class CompanyCreated extends ShouldBeStored
{
    public function __construct(
        public string $name,
        public CarbonInterface $trialEndsAt,
    ) {}
}
