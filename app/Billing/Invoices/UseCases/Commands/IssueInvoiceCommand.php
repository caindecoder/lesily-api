<?php

namespace App\Billing\Invoices\UseCases\Commands;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Issue an invoice (SM-1-04 T1 genesis). Carries the full InvoiceCreated payload
 * inputs — the aggregate ships caller-less this slice (B4), so no interactor
 * derives recipient/number context yet; the payment-slice producers will build
 * this command from subscription state. The invoice number is caller-supplied —
 * sequence generation defers to the payment slice (ERD-1-04 OQ#2).
 *
 * An internal Spatie\LaravelData command (aggregate-takes-Command, s071) — built
 * via ::from() from server-minted values, so it carries no rules() (conv-plan
 * §2.15.B.2, amendment AQ).
 */
class IssueInvoiceCommand extends Data
{
    public string $invoiceNumber;

    public string $subscriptionId;

    public string $companyId;

    public int $amountCents;

    public string $currency;

    /** @var array<int, array<string, mixed>> */
    public array $lineItems;

    public CarbonImmutable $dueDate;

    public string $source;

    public string $recipientName;

    public string $recipientEmail;

    /** @var ?array<string, mixed> */
    public ?array $recipientBillingAddress = null;

    public ?string $createdBy = null;
}
