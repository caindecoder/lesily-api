<?php

namespace App\Billing\Invoices\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * SM-1-04 modifier — attaches an open dispute to the invoice without a state
 * transition (has_dispute flag, s133 F8). Resolution events land with the
 * dispute slice (UC-1-054).
 */
class InvoiceDisputeFiled extends ShouldBeStored
{
    public function __construct(
        public string $invoiceId,
        public string $disputeId,
        public CarbonInterface $filedAt,
        public string $reason,
        public string $filedBy,
    ) {}
}
