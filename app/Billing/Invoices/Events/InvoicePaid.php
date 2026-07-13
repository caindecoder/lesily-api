<?php

namespace App\Billing\Invoices\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * SM-1-04 T2 (Pending → Paid) and T4 (Failed → Paid) — one event class, the
 * source discriminator carries the distinction ('first-attempt' |
 * 'retry-recovery'; 'updated-payment-method' arrives with the payment slice).
 */
class InvoicePaid extends ShouldBeStored
{
    public function __construct(
        public string $invoiceId,
        public CarbonInterface $paidAt,
        public int $amountCents,
        public string $payHereTransactionId,
        public string $payHereEventId,
        public string $source,
    ) {}
}
