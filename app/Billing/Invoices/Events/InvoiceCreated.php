<?php

namespace App\Billing\Invoices\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * SM-1-04 T1 genesis ([*] → Pending). Carries the full recipient/line-item
 * snapshot per cd-invoices §3 — invoices are immutable financial records, so
 * the event holds everything the receipt and projection ever need. Dates typed
 * CarbonInterface so they survive spatie's denormalise-to-mutable-Carbon on
 * replay (SubscriptionTrialStarted precedent).
 */
class InvoiceCreated extends ShouldBeStored
{
    /**
     * @param  array<int, array<string, mixed>>  $lineItems
     * @param  ?array<string, mixed>  $recipientBillingAddress
     */
    public function __construct(
        public string $invoiceId,
        public string $subscriptionId,
        public string $companyId,
        public string $invoiceNumber,
        public int $amountCents,
        public string $currency,
        public array $lineItems,
        public CarbonInterface $dueDate,
        public CarbonInterface $issuedAt,
        public string $source,
        public string $recipientName,
        public string $recipientEmail,
        public ?array $recipientBillingAddress,
        public ?string $createdBy,
    ) {}
}
