<?php

namespace App\Foundation\AccessLog\Values;

use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\AccessLog\Enums\AccessEventReason;

/**
 * What a use case decided about a request, noted for the access log before the
 * response is known.
 */
readonly class AccessOutcome
{
    public function __construct(
        public AccessEvent $event,
        public ?AccessEventReason $reason,
        public ?string $userId,
        public ?string $companyId = null,
    ) {}
}
