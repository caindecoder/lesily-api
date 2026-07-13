<?php

namespace App\Billing\Invoices\UseCases\Commands;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Issue an invoice (SM-1-04 T1 genesis). Carries the full InvoiceCreated payload
 * inputs — the aggregate ships caller-less this slice (B4), so no interactor
 * derives recipient/number context yet; the payment-slice producers will build
 * this command from subscription state. The invoice number is caller-supplied —
 * sequence generation defers to the payment slice (ERD-1-04 OQ#2).
 *
 * A Spatie\LaravelData command (aggregate-takes-Command, s071) — constructed via
 * ::validateAndCreate() so inputs are validated at the use-case boundary.
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

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'invoiceNumber' => ['string', 'max:191'],
            'subscriptionId' => ['uuid'],
            'companyId' => ['uuid'],
            'amountCents' => ['integer', 'min:1'],
            'currency' => ['string', 'size:3'],
            'lineItems' => ['array', 'min:1'],
            'lineItems.*.description' => ['string', 'max:191'],
            'lineItems.*.quantity' => ['numeric'],
            'lineItems.*.unitPriceCents' => ['integer', 'min:0'],
            'lineItems.*.amountCents' => ['integer'],
            'lineItems.*.lineType' => ['in:plan_subscription,addon,proration_credit,proration_charge'],
            'lineItems.*.sortOrder' => ['integer', 'min:0'],
            'dueDate' => ['date'],
            'source' => ['in:subscribe,upgrade-prorated,downgrade-applied,cycle-change,addon,reactivation,restoration,recurring'],
            'recipientName' => ['string', 'max:191'],
            'recipientEmail' => ['email', 'max:191'],
        ];
    }
}
