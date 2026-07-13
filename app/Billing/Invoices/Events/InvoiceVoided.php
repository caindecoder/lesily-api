<?php

namespace App\Billing\Invoices\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * SM-1-04 T7 (Pending → Voided) and T8 (Failed → Voided) — no charge was
 * collected; the source discriminator records why. Terminal.
 */
class InvoiceVoided extends ShouldBeStored
{
    public function __construct(
        public string $invoiceId,
        public CarbonInterface $voidedAt,
        public string $voidedBy,
        public string $reason,
        public string $source,
    ) {}
}
