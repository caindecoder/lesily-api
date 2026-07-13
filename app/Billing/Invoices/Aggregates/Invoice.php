<?php

namespace App\Billing\Invoices\Aggregates;

use Carbon\CarbonImmutable;
use App\Billing\Invoices\Events\InvoicePaid;
use App\Foundation\EventStore\AggregateRoot;
use App\Billing\Invoices\Enums\InvoiceStatus;
use App\Billing\Invoices\Events\InvoiceVoided;
use App\Billing\Invoices\Events\InvoiceCreated;
use App\Billing\Invoices\Events\InvoiceRefunded;
use App\Billing\Invoices\Events\InvoicePaymentFailed;
use App\Billing\Invoices\UseCases\Commands\VoidInvoiceCommand;
use App\Billing\Invoices\Exceptions\InvoiceNotPayableException;
use App\Billing\Invoices\UseCases\Commands\IssueInvoiceCommand;
use App\Billing\Invoices\UseCases\Commands\RefundInvoiceCommand;
use App\Billing\Invoices\Exceptions\InvoiceAlreadyIssuedException;
use App\Billing\Invoices\Exceptions\InvoiceLineItemsMismatchException;
use App\Billing\Invoices\UseCases\Commands\RecordPaymentFailedCommand;
use App\Billing\Invoices\UseCases\Commands\RecordPaymentSucceededCommand;
use App\Billing\Invoices\Exceptions\InvoicePaymentAmountMismatchException;

class Invoice extends AggregateRoot
{
    protected ?InvoiceStatus $status = null;

    /** @var array<int, string> */
    protected array $seenPayHereEventIds = [];

    protected int $amountCents = 0;

    public function issue(IssueInvoiceCommand $command): static
    {
        if ($this->status !== null) {
            throw InvoiceAlreadyIssuedException::withId($this->uuid());
        }

        $lineItemsTotalCents = (int) collect($command->lineItems)->sum('amountCents');
        if ($lineItemsTotalCents !== $command->amountCents) {
            throw InvoiceLineItemsMismatchException::forInvoice($this->uuid(), $command->amountCents, $lineItemsTotalCents);
        }

        return $this->forCompany($command->companyId)
            ->recordThat(new InvoiceCreated(
                invoiceId: $this->uuid(),
                subscriptionId: $command->subscriptionId,
                companyId: $command->companyId,
                invoiceNumber: $command->invoiceNumber,
                amountCents: $command->amountCents,
                currency: $command->currency,
                lineItems: $command->lineItems,
                dueDate: $command->dueDate,
                issuedAt: CarbonImmutable::now(),
                source: $command->source,
                recipientName: $command->recipientName,
                recipientEmail: $command->recipientEmail,
                recipientBillingAddress: $command->recipientBillingAddress,
                createdBy: $command->createdBy,
            ));
    }

    public function recordPaymentSucceeded(RecordPaymentSucceededCommand $command): static
    {
        // Webhook replay (tier-1 idempotency): a seen PayHere event id is
        // absorbed without recording — replays produce no state changes (SM-1-04 §6).
        if (in_array($command->payHereEventId, $this->seenPayHereEventIds, true)) {
            return $this;
        }

        if (! in_array($this->status, [InvoiceStatus::Pending, InvoiceStatus::Failed], true)) {
            throw InvoiceNotPayableException::forInvoice($this->uuid(), $this->status?->value ?? 'unissued');
        }

        if ($command->amountCents !== $this->amountCents) {
            throw InvoicePaymentAmountMismatchException::forInvoice($this->uuid(), $this->amountCents, $command->amountCents);
        }

        return $this->recordThat(new InvoicePaid(
            invoiceId: $this->uuid(),
            paidAt: CarbonImmutable::now(),
            amountCents: $command->amountCents,
            payHereTransactionId: $command->payHereTransactionId,
            payHereEventId: $command->payHereEventId,
            source: $this->status === InvoiceStatus::Failed ? 'retry-recovery' : 'first-attempt',
        ));
    }

    public function recordPaymentFailed(RecordPaymentFailedCommand $command): static
    {
        return $this->recordThat(new InvoicePaymentFailed(
            invoiceId: $this->uuid(),
            failedAt: CarbonImmutable::now(),
            amountCents: $this->amountCents,
            payHereEventId: $command->payHereEventId,
            failureCode: $command->failureCode,
            failureMessage: $command->failureMessage,
            attemptNumber: $command->attemptNumber,
        ));
    }

    public function refund(RefundInvoiceCommand $command): static
    {
        return $this->recordThat(new InvoiceRefunded(
            invoiceId: $this->uuid(),
            refundedAt: CarbonImmutable::now(),
            refundAmountCents: $command->refundAmountCents,
            payHereRefundTransactionId: $command->payHereRefundTransactionId,
            source: $command->source,
            reason: $command->reason,
        ));
    }

    public function void(VoidInvoiceCommand $command): static
    {
        return $this->recordThat(new InvoiceVoided(
            invoiceId: $this->uuid(),
            voidedAt: CarbonImmutable::now(),
            voidedBy: $command->voidedBy,
            reason: $command->reason,
            source: $command->source,
        ));
    }

    protected function applyInvoiceCreated(InvoiceCreated $event): void
    {
        $this->status = InvoiceStatus::Pending;
        $this->amountCents = $event->amountCents;
    }

    protected function applyInvoicePaid(InvoicePaid $event): void
    {
        $this->status = InvoiceStatus::Paid;
        $this->seenPayHereEventIds[] = $event->payHereEventId;
    }

    protected function applyInvoicePaymentFailed(InvoicePaymentFailed $event): void
    {
        $this->status = InvoiceStatus::Failed;
    }

    protected function applyInvoiceRefunded(InvoiceRefunded $event): void
    {
        $this->status = InvoiceStatus::Refunded;
    }

    protected function applyInvoiceVoided(InvoiceVoided $event): void
    {
        $this->status = InvoiceStatus::Voided;
    }
}
