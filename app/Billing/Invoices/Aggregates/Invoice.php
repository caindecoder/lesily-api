<?php

namespace App\Billing\Invoices\Aggregates;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Billing\Invoices\Events\InvoicePaid;
use App\Foundation\EventStore\AggregateRoot;
use App\Billing\Invoices\Enums\InvoiceStatus;
use App\Billing\Invoices\Events\InvoiceVoided;
use App\Billing\Invoices\Events\InvoiceCreated;
use App\Billing\Invoices\Events\InvoiceRefunded;
use App\Billing\Invoices\Events\InvoiceDisputeFiled;
use App\Billing\Invoices\Events\InvoicePaymentFailed;
use App\Billing\Invoices\UseCases\Commands\VoidInvoiceCommand;
use App\Billing\Invoices\Exceptions\InvoiceNotPayableException;
use App\Billing\Invoices\UseCases\Commands\IssueInvoiceCommand;
use App\Billing\Invoices\Exceptions\InvoiceNotVoidableException;
use App\Billing\Invoices\UseCases\Commands\RefundInvoiceCommand;
use App\Billing\Invoices\Exceptions\InvoiceAlreadyIssuedException;
use App\Billing\Invoices\Exceptions\InvoiceNotRefundableException;
use App\Billing\Invoices\UseCases\Commands\FileInvoiceDisputeCommand;
use App\Billing\Invoices\Exceptions\InvoiceAmountNotPositiveException;
use App\Billing\Invoices\Exceptions\InvoiceDisputeNotAllowedException;
use App\Billing\Invoices\Exceptions\InvoiceLineItemsMismatchException;
use App\Billing\Invoices\UseCases\Commands\RecordPaymentFailedCommand;
use App\Billing\Invoices\Exceptions\InvoiceRefundAmountMismatchException;
use App\Billing\Invoices\UseCases\Commands\RecordPaymentSucceededCommand;
use App\Billing\Invoices\Exceptions\InvoicePaymentAmountMismatchException;

class Invoice extends AggregateRoot
{
    protected ?InvoiceStatus $status = null;

    /** @var array<int, string> */
    protected array $seenPayHereEventIds = [];

    protected int $amountCents = 0;

    protected bool $hasOpenDispute = false;

    public function issue(IssueInvoiceCommand $command): static
    {
        if ($this->status !== null) {
            throw InvoiceAlreadyIssuedException::withId($this->uuid());
        }

        // SM-1-04 T1: an invoice is only ever issued for a positive amount.
        if ($command->amountCents < 1) {
            throw InvoiceAmountNotPositiveException::forInvoice($this->uuid(), $command->amountCents);
        }

        $lineItemsTotalCents = collect($command->lineItems)->sum('amountCents');
        if ($lineItemsTotalCents !== $command->amountCents) {
            throw InvoiceLineItemsMismatchException::forInvoice($this->uuid(), $command->amountCents, $lineItemsTotalCents);
        }

        return $this->forCompany($command->company->getKey())
            ->recordThat(new InvoiceCreated(
                invoiceId: $this->uuid(),
                subscriptionId: $command->subscription->getKey(),
                companyId: $command->company->getKey(),
                invoiceNumber: $command->invoiceNumber,
                amountCents: $command->amountCents,
                currency: $command->currency->value,
                lineItems: $command->lineItems,
                dueDate: $command->dueDate,
                issuedAt: CarbonImmutable::now(),
                source: $command->source->value,
                recipientName: $command->recipientName,
                recipientEmail: $command->recipientEmail,
                recipientBillingAddress: $command->recipientBillingAddress,
                createdBy: $command->createdBy?->getKey(),
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
            throw InvoiceNotPayableException::forInvoice($this->uuid(), $this->status->value ?? 'unissued');
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
        // Webhook replay (tier-1 idempotency): a seen PayHere event id is
        // absorbed without recording — replays produce no state changes (SM-1-04 §6).
        // Order matters: absorption precedes the status guard so a redelivered
        // failure on a since-recovered (paid) invoice stays a silent no-op.
        if (in_array($command->payHereEventId, $this->seenPayHereEventIds, true)) {
            return $this;
        }

        if (! in_array($this->status, [InvoiceStatus::Pending, InvoiceStatus::Failed], true)) {
            throw InvoiceNotPayableException::forInvoice($this->uuid(), $this->status->value ?? 'unissued');
        }

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
        if (! in_array($this->status, [InvoiceStatus::Paid, InvoiceStatus::Failed], true)) {
            throw InvoiceNotRefundableException::forInvoice($this->uuid(), $this->status->value ?? 'unissued');
        }

        // Chunk-1 MVP: full refunds only (SM-1-04 OQ#6 — partial refunds deferred).
        if ($command->refundAmountCents !== $this->amountCents) {
            throw InvoiceRefundAmountMismatchException::forInvoice($this->uuid(), $this->amountCents, $command->refundAmountCents);
        }

        return $this->recordThat(new InvoiceRefunded(
            invoiceId: $this->uuid(),
            refundedAt: CarbonImmutable::now(),
            refundAmountCents: $command->refundAmountCents,
            payHereRefundTransactionId: $command->payHereRefundTransactionId,
            source: $command->source->value,
            reason: $command->reason,
        ));
    }

    public function void(VoidInvoiceCommand $command): static
    {
        if (! in_array($this->status, [InvoiceStatus::Pending, InvoiceStatus::Failed], true)) {
            throw InvoiceNotVoidableException::forInvoice($this->uuid(), $this->status->value ?? 'unissued');
        }

        return $this->recordThat(new InvoiceVoided(
            invoiceId: $this->uuid(),
            voidedAt: CarbonImmutable::now(),
            voidedBy: $command->voidedBy->getKey(),
            reason: $command->reason,
            source: $command->source->value,
        ));
    }

    public function fileDispute(FileInvoiceDisputeCommand $command): static
    {
        if ($this->hasOpenDispute) {
            throw InvoiceDisputeNotAllowedException::alreadyOpen($this->uuid());
        }

        // Disputes attach to pending, failed or paid invoices only (SM-1-04 §4 note).
        if (! in_array($this->status, [InvoiceStatus::Pending, InvoiceStatus::Failed, InvoiceStatus::Paid], true)) {
            throw InvoiceDisputeNotAllowedException::forStatus($this->uuid(), $this->status->value ?? 'unissued');
        }

        return $this->recordThat(new InvoiceDisputeFiled(
            invoiceId: $this->uuid(),
            disputeId: Str::uuid()->toString(),
            filedAt: CarbonImmutable::now(),
            reason: $command->reason,
            filedBy: $command->filedBy->getKey(),
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
        $this->seenPayHereEventIds[] = $event->payHereEventId;
    }

    protected function applyInvoiceRefunded(InvoiceRefunded $event): void
    {
        $this->status = InvoiceStatus::Refunded;
    }

    protected function applyInvoiceVoided(InvoiceVoided $event): void
    {
        $this->status = InvoiceStatus::Voided;
    }

    protected function applyInvoiceDisputeFiled(InvoiceDisputeFiled $event): void
    {
        $this->hasOpenDispute = true;
    }
}
