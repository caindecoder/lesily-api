<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use App\Companies\Entities\Company;
use App\Billing\Invoices\Aggregates\Invoice;
use App\Billing\Invoices\Entities\Invoice as InvoiceReadModel;
use App\Billing\Invoices\UseCases\Commands\VoidInvoiceCommand;
use App\Billing\Invoices\UseCases\Commands\IssueInvoiceCommand;
use App\Billing\Invoices\UseCases\Commands\RefundInvoiceCommand;
use App\Billing\Invoices\UseCases\Commands\FileInvoiceDisputeCommand;
use App\Billing\Invoices\UseCases\Commands\RecordPaymentFailedCommand;
use App\Billing\Invoices\UseCases\Commands\RecordPaymentSucceededCommand;
use App\Billing\Subscriptions\Entities\Subscription as SubscriptionReadModel;

trait CreatesInvoices
{
    /**
     * A valid issue command against $subscription's company — two line items
     * whose signed amounts sum to the invoice total (the proration-credit row
     * exercises ERD-1-04's signed line-item amount). Override any top-level
     * field per test; the fixed invoice number is safe per-test (RefreshDatabase
     * isolates rows) — pass an override where a test needs several invoices.
     */
    protected function issueInvoiceCommand(Company $company, SubscriptionReadModel $subscription, array $overrides = []): IssueInvoiceCommand
    {
        return IssueInvoiceCommand::from([
            'invoiceNumber' => 'INV-2026-000001',
            'subscription' => $subscription,
            'company' => $company,
            'amountCents' => 60000,
            'currency' => 'LKR',
            'lineItems' => [
                [
                    'description' => 'Pro Plan — Monthly',
                    'quantity' => 1,
                    'unitPriceCents' => 75000,
                    'amountCents' => 75000,
                    'lineType' => 'plan_subscription',
                    'sortOrder' => 0,
                ],
                [
                    'description' => 'Proration credit — unused Starter days',
                    'quantity' => -1,
                    'unitPriceCents' => 15000,
                    'amountCents' => -15000,
                    'lineType' => 'proration_credit',
                    'sortOrder' => 1,
                ],
            ],
            'dueDate' => CarbonImmutable::now()->addDays(14),
            'source' => 'subscribe',
            'recipientName' => 'ACME (Pvt) Ltd',
            'recipientEmail' => 'billing@acme.example',
            ...$overrides,
        ]);
    }

    /**
     * An issued (pending) invoice projected for $company through the domain
     * seam — the genesis path production takes, never a direct row write.
     */
    protected function anIssuedInvoiceFor(Company $company, SubscriptionReadModel $subscription, array $overrides = []): InvoiceReadModel
    {
        $invoiceId = (string) Str::uuid();

        Invoice::retrieve($invoiceId)
            ->issue($this->issueInvoiceCommand($company, $subscription, $overrides))
            ->persist();

        return InvoiceReadModel::findOrFail($invoiceId);
    }

    /**
     * An invoice paid through the domain seam (issue → payment webhook).
     */
    protected function aPaidInvoiceFor(Company $company, SubscriptionReadModel $subscription, array $overrides = []): InvoiceReadModel
    {
        $invoice = $this->anIssuedInvoiceFor($company, $subscription, $overrides);

        Invoice::retrieve($invoice->getKey())
            ->recordPaymentSucceeded($this->recordPaymentSucceededCommand($invoice))
            ->persist();

        return $invoice->refresh();
    }

    /**
     * An invoice whose first payment attempt failed, through the domain seam (T3).
     */
    protected function aFailedInvoiceFor(Company $company, SubscriptionReadModel $subscription, array $overrides = []): InvoiceReadModel
    {
        $invoice = $this->anIssuedInvoiceFor($company, $subscription, $overrides);

        Invoice::retrieve($invoice->getKey())
            ->recordPaymentFailed($this->recordPaymentFailedCommand($invoice))
            ->persist();

        return $invoice->refresh();
    }

    /**
     * A valid payment-failure webhook command for $invoice — first attempt,
     * fresh PayHere event id. Override per test (attempt numbers, replays).
     */
    protected function recordPaymentFailedCommand(InvoiceReadModel $invoice, array $overrides = []): RecordPaymentFailedCommand
    {
        return RecordPaymentFailedCommand::from([
            'payHereEventId' => 'evt_fail_'.$invoice->getKey(),
            'failureCode' => 'insufficient_funds',
            'failureMessage' => 'Card has insufficient funds.',
            'attemptNumber' => 1,
            ...$overrides,
        ]);
    }

    /**
     * A paid invoice subsequently refunded through the domain seam (T6).
     */
    protected function aRefundedInvoiceFor(Company $company, SubscriptionReadModel $subscription, array $overrides = []): InvoiceReadModel
    {
        $invoice = $this->aPaidInvoiceFor($company, $subscription, $overrides);

        Invoice::retrieve($invoice->getKey())
            ->refund($this->refundInvoiceCommand($invoice))
            ->persist();

        return $invoice->refresh();
    }

    /**
     * A valid full-refund command for $invoice (admin-refund, PayHere ref).
     * Override per test (T5 credit memos, amount mismatches).
     */
    protected function refundInvoiceCommand(InvoiceReadModel $invoice, array $overrides = []): RefundInvoiceCommand
    {
        return RefundInvoiceCommand::from([
            'refundAmountCents' => $invoice->amount_cents,
            'payHereRefundTransactionId' => 'refund_txn_'.$invoice->getKey(),
            'source' => 'admin-refund',
            'reason' => 'Goodwill refund issued by platform support.',
            ...$overrides,
        ]);
    }

    /**
     * A pending invoice voided through the domain seam (T7).
     */
    protected function aVoidedInvoiceFor(Company $company, SubscriptionReadModel $subscription, array $overrides = []): InvoiceReadModel
    {
        $invoice = $this->anIssuedInvoiceFor($company, $subscription, $overrides);

        Invoice::retrieve($invoice->getKey())
            ->void($this->voidInvoiceCommand($invoice))
            ->persist();

        return $invoice->refresh();
    }

    /**
     * A valid admin-correction void command for $invoice, actor freshly minted.
     * Override per test (dispute-pre-payment source, specific actors).
     */
    protected function voidInvoiceCommand(InvoiceReadModel $invoice, array $overrides = []): VoidInvoiceCommand
    {
        return VoidInvoiceCommand::from([
            'voidedBy' => User::factory()->create(),
            'reason' => 'Issued in error - admin correction.',
            'source' => 'admin-correction',
            ...$overrides,
        ]);
    }

    /**
     * A pending invoice with an open dispute filed through the domain seam.
     */
    protected function aDisputedInvoiceFor(Company $company, SubscriptionReadModel $subscription, array $overrides = []): InvoiceReadModel
    {
        $invoice = $this->anIssuedInvoiceFor($company, $subscription, $overrides);

        Invoice::retrieve($invoice->getKey())
            ->fileDispute($this->fileInvoiceDisputeCommand($invoice))
            ->persist();

        return $invoice->refresh();
    }

    /**
     * A valid dispute-filing command for $invoice, filed by a fresh owner-class
     * actor. Override per test.
     */
    protected function fileInvoiceDisputeCommand(InvoiceReadModel $invoice, array $overrides = []): FileInvoiceDisputeCommand
    {
        return FileInvoiceDisputeCommand::from([
            'reason' => 'This charge does not match our agreed plan.',
            'filedBy' => User::factory()->create(),
            ...$overrides,
        ]);
    }

    /**
     * A PAID invoice with an open dispute filed through the domain seam — the
     * F7 receipt-block state (the aggregate permits disputes on Paid, T3).
     */
    protected function aPaidDisputedInvoiceFor(Company $company, SubscriptionReadModel $subscription, array $overrides = []): InvoiceReadModel
    {
        $invoice = $this->aPaidInvoiceFor($company, $subscription, $overrides);

        Invoice::retrieve($invoice->getKey())
            ->fileDispute($this->fileInvoiceDisputeCommand($invoice))
            ->persist();

        return $invoice->refresh();
    }

    /**
     * A disputed invoice subsequently REFUNDED through the domain seam — the
     * aggregate permits refund() from Paid regardless of the open dispute, so
     * refunded + has_dispute is a reachable state (test-analyzer MINOR-5).
     */
    protected function aRefundedDisputedInvoiceFor(Company $company, SubscriptionReadModel $subscription, array $overrides = []): InvoiceReadModel
    {
        $invoice = $this->aPaidDisputedInvoiceFor($company, $subscription, $overrides);

        Invoice::retrieve($invoice->getKey())
            ->refund($this->refundInvoiceCommand($invoice))
            ->persist();

        return $invoice->refresh();
    }

    /**
     * Seam-fixture dispatch for DataProvider state matrices (§2.12) — every
     * state is arranged through real aggregate commands, never row writes.
     */
    protected function anInvoiceInState(string $state, SubscriptionReadModel $subscription, array $overrides = []): InvoiceReadModel
    {
        $company = Company::findOrFail($subscription->company_id);

        return match ($state) {
            'pending' => $this->anIssuedInvoiceFor($company, $subscription, $overrides),
            'paid' => $this->aPaidInvoiceFor($company, $subscription, $overrides),
            'failed' => $this->aFailedInvoiceFor($company, $subscription, $overrides),
            'refunded' => $this->aRefundedInvoiceFor($company, $subscription, $overrides),
            'voided' => $this->aVoidedInvoiceFor($company, $subscription, $overrides),
            default => throw new InvalidArgumentException("Unknown invoice state fixture [{$state}]."),
        };
    }

    /**
     * A valid payment-succeeded webhook command for $invoice — full amount,
     * fresh PayHere identifiers. Override per test (e.g. replayed event ids).
     */
    protected function recordPaymentSucceededCommand(InvoiceReadModel $invoice, array $overrides = []): RecordPaymentSucceededCommand
    {
        return RecordPaymentSucceededCommand::from([
            'payHereEventId' => 'evt_'.$invoice->getKey(),
            'payHereTransactionId' => 'txn_'.$invoice->getKey(),
            'amountCents' => $invoice->amount_cents,
            ...$overrides,
        ]);
    }
}
