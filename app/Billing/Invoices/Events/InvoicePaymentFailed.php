<?php

namespace App\Billing\Invoices\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * SM-1-04 T3 (Pending → Failed) when attemptNumber is 1; retry failures
 * (attemptNumber > 1) are recorded for dunning history without a state
 * transition (SM-1-04 §5 — no self-loop for retry attempts).
 */
class InvoicePaymentFailed extends ShouldBeStored
{
    public function __construct(
        public string $invoiceId,
        public CarbonInterface $failedAt,
        public int $amountCents,
        public string $payHereEventId,
        public string $failureCode,
        public ?string $failureMessage,
        public int $attemptNumber,
    ) {}
}
