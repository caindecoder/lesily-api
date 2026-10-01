<?php

namespace App\Compliance\Dsar\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class DsarRequestSubmitted extends ShouldBeStored
{
    public function __construct(
        public string $requestId,
        public string $companyId,
        public string $subjectUserId,
        public string $requestedByUserId,
        public string $requestedByRole,
        public string $requestType,
        public CarbonInterface $requestedAt,
        public CarbonInterface $slaDeadline,
    ) {}
}
