<?php

namespace App\Billing\Invoices\UseCases\Commands;

use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;
use App\Billing\Invoices\Enums\Currency;
use App\Billing\Invoices\Enums\InvoiceSource;
use App\Billing\Subscriptions\Entities\Subscription;

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

    public Subscription $subscription;

    public Company $company;

    public int $amountCents;

    public Currency $currency;

    /** @var array<int, array<string, mixed>> */
    public array $lineItems;

    public CarbonImmutable $dueDate;

    public InvoiceSource $source;

    public string $recipientName;

    public string $recipientEmail;

    /** @var ?array<string, mixed> */
    public ?array $recipientBillingAddress = null;

    public ?User $createdBy = null;
}
