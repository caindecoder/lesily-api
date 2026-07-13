<?php

namespace App\Billing\Invoices\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * SM-1-04 T5 (Failed → Refunded, credit memo — NULL PayHere ref, no money
 * moved) and T6 (Paid → Refunded, real PayHere refund). Terminal.
 */
class InvoiceRefunded extends ShouldBeStored
{
    public function __construct(
        public string $invoiceId,
        public CarbonInterface $refundedAt,
        public int $refundAmountCents,
        public ?string $payHereRefundTransactionId,
        public string $source,
        public string $reason,
    ) {}
}
