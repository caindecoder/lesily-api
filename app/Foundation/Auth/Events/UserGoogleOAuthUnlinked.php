<?php

namespace App\Foundation\Auth\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class UserGoogleOAuthUnlinked extends ShouldBeStored
{
    public function __construct(
        public string $userId,
        public CarbonInterface $unlinkedAt,
    ) {}
}
