<?php

namespace App\Companies\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CompanyCreated extends ShouldBeStored
{
    public function __construct(
        public string $name,
        public int $trialDays,
    ) {}
}
