<?php

namespace App\Foundation\Auth\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class UserGoogleOAuthLinked extends ShouldBeStored
{
    public function __construct(
        public string $userId,
        public string $googleSubjectId,
        public CarbonInterface $linkedAt,
    ) {}
}
