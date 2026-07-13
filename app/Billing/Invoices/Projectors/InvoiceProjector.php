<?php

namespace App\Billing\Invoices\Projectors;

use App\Billing\Invoices\Events\InvoicePaid;
use App\Billing\Invoices\Enums\InvoiceStatus;
use App\Billing\Invoices\Events\InvoiceVoided;
use App\Billing\Invoices\Events\InvoiceCreated;
use App\Billing\Invoices\Events\InvoiceRefunded;
use App\Billing\Invoices\Entities\InvoiceLineItem;
use App\Billing\Invoices\Events\InvoiceDisputeFiled;
use App\Billing\Invoices\Events\InvoicePaymentFailed;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use App\Billing\Invoices\Entities\Invoice as InvoiceReadModel;

/**
 * Mirrors the Invoice aggregate's lifecycle events into the `invoices` +
 * `invoice_line_items` read models (ERD-1-04). Line items are inserted with the
 * parent invoice on InvoiceCreated and never touched again (immutable per the
 * ERD deviation). Sole writer of both tables (conv-plan §2.15).
 */
class InvoiceProjector extends Projector
{
    public function onInvoiceCreated(InvoiceCreated $event): void
    {
        InvoiceReadModel::query()->create([
            'id' => $event->aggregateRootUuid(),
            'company_id' => $event->companyId,
            'subscription_id' => $event->subscriptionId,
            'invoice_number' => $event->invoiceNumber,
            'status' => InvoiceStatus::Pending,
            'source' => $event->source,
            'amount_cents' => $event->amountCents,
            'currency' => $event->currency,
            'due_date' => $event->dueDate,
            'issued_at' => $event->issuedAt,
            'recipient_name' => $event->recipientName,
            'recipient_email' => $event->recipientEmail,
            'recipient_billing_address' => $event->recipientBillingAddress,
            'created_by' => $event->createdBy,
        ]);

        foreach ($event->lineItems as $item) {
            $this->insertLineItem($event, $item);
        }
    }

    public function onInvoicePaid(InvoicePaid $event): void
    {
        InvoiceReadModel::query()->findOrFail($event->aggregateRootUuid())
            ->update([
                'status' => InvoiceStatus::Paid,
                'paid_at' => $event->paidAt,
                'payhere_transaction_id' => $event->payHereTransactionId,
            ]);
    }

    public function onInvoiceRefunded(InvoiceRefunded $event): void
    {
        InvoiceReadModel::query()->findOrFail($event->aggregateRootUuid())
            ->update([
                'status' => InvoiceStatus::Refunded,
                'refunded_at' => $event->refundedAt,
                'refund_amount_cents' => $event->refundAmountCents,
                'payhere_refund_transaction_id' => $event->payHereRefundTransactionId,
                'refund_source' => $event->source,
                'refund_reason' => $event->reason,
            ]);
    }

    public function onInvoiceVoided(InvoiceVoided $event): void
    {
        InvoiceReadModel::query()->findOrFail($event->aggregateRootUuid())
            ->update([
                'status' => InvoiceStatus::Voided,
                'voided_at' => $event->voidedAt,
                'voided_by' => $event->voidedBy,
                'void_reason' => $event->reason,
                'void_source' => $event->source,
            ]);
    }

    public function onInvoiceDisputeFiled(InvoiceDisputeFiled $event): void
    {
        // Modifier — no state transition; dispute-resolution events clear the
        // flag in the dispute slice (s133 F8; UC-1-120 disputed filter + UC-1-121 F7 block).
        InvoiceReadModel::query()->findOrFail($event->aggregateRootUuid())
            ->update(['has_dispute' => true]);
    }

    public function onInvoicePaymentFailed(InvoicePaymentFailed $event): void
    {
        $invoice = InvoiceReadModel::query()->findOrFail($event->aggregateRootUuid());

        $invoice->update([
            'status' => InvoiceStatus::Failed,
            'failed_at' => $event->failedAt,
            'failure_count' => $invoice->failure_count + 1,
            'last_failure_code' => $event->failureCode,
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function insertLineItem(InvoiceCreated $event, array $item): void
    {
        InvoiceLineItem::query()->create([
            'company_id' => $event->companyId,
            'invoice_id' => $event->aggregateRootUuid(),
            'description' => $item['description'],
            'quantity' => $item['quantity'],
            'unit_price_cents' => $item['unitPriceCents'],
            'amount_cents' => $item['amountCents'],
            'currency' => $event->currency,
            'line_type' => $item['lineType'],
            'reference_id' => $item['referenceId'] ?? null,
            'reference_type' => $item['referenceType'] ?? null,
            'period_start' => $item['periodStart'] ?? null,
            'period_end' => $item['periodEnd'] ?? null,
            'sort_order' => $item['sortOrder'],
        ]);
    }
}
