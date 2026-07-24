<?php

namespace App\Billing\Invoices\IO\Http\Resources;

use Illuminate\Http\Request;
use App\Billing\Invoices\Entities\Invoice;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Billing\Invoices\Entities\InvoiceLineItem;

/**
 * The platform-admin view of one invoice (UC-1-122) — full designed shape per
 * api-1-invoices §3 admin row + the s137 F-c contract pin (B5-style: every
 * designed key emitted; composites null until their producing slices).
 * Exact-envelope leak-guarded (spec §6).
 *
 * @property-read Invoice $resource
 */
class InvoiceAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'number' => $this->resource->invoice_number,
            'issued_at' => $this->resource->issued_at->toJSON(),
            'due_at' => $this->resource->due_date->toDateString(),
            'status' => $this->resource->status->value,
            'amount_cents' => $this->resource->amount_cents,
            'currency' => $this->resource->currency,
            'has_dispute' => $this->resource->has_dispute,
            'source' => $this->resource->source,
            'paid_at' => $this->resource->paid_at?->toJSON(),
            'payhere_transaction_id' => $this->resource->payhere_transaction_id,
            'failed_at' => $this->resource->failed_at?->toJSON(),
            'failure_count' => $this->resource->failure_count,
            'last_failure_code' => $this->resource->last_failure_code,
            // Null until the payment slice builds retry scheduling (F-c).
            'next_retry_at' => null,
            'voided_at' => $this->resource->voided_at?->toJSON(),
            'voided_by' => $this->resource->voided_by,
            'void_reason' => $this->resource->void_reason,
            'void_source' => $this->resource->void_source,
            'refunded_at' => $this->resource->refunded_at?->toJSON(),
            'refund_amount_cents' => $this->resource->refund_amount_cents,
            'payhere_refund_transaction_id' => $this->resource->payhere_refund_transaction_id,
            'refund_reason' => $this->resource->refund_reason,
            'refund_source' => $this->resource->refund_source,
            'recipient_name' => $this->resource->recipient_name,
            'recipient_email' => $this->resource->recipient_email,
            'recipient_billing_address' => $this->resource->recipient_billing_address,
            'line_items' => $this->lineItems(),
            // Null until the payment slice builds invoice_payment_attempts (F-c).
            'payment_attempts' => null,
            // Null until the dispute slice builds the thread (UC-1-054; F-c).
            'dispute_timeline' => null,
            // Null until the UC-1-066 audit-feed slice (F-c).
            'audit_trail_summary' => null,
            // Constant false until the platform-admin slice builds company
            // soft-deletion (UC-1-095 pathway; s137 F-a ruling).
            'tenant_soft_deleted' => false,
            // Null until OQ-1-135 delivers the legal-hold SETTER (UC-1-039/040)
            // and its reason/date/order_reference companions. The bare boolean
            // column landed in slice-#11 Task 4 for the ownership-transfer guard,
            // but nothing can set it true yet, so surfacing a constant false here
            // would read as "we checked the hold" when no hold can exist.
            'legal_hold' => null,
            'company' => $this->companySummary(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lineItems(): array
    {
        return $this->resource->lineItems
            ->map(fn (InvoiceLineItem $line): array => [
                'id' => $line->id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price_cents' => $line->unit_price_cents,
                'amount_cents' => $line->amount_cents,
                'currency' => $line->currency,
                'line_type' => $line->line_type,
                'reference_id' => $line->reference_id,
                'reference_type' => $line->reference_type,
                'period_start' => $line->period_start?->toJSON(),
                'period_end' => $line->period_end?->toJSON(),
                'sort_order' => $line->sort_order,
            ])
            ->all();
    }

    /**
     * The owning-company summary (name, BR/EPF, plan, status — UC-1-122 Main
     * Flow step 4). Plan null-safe (NULL during trial — the s066 pin); status
     * is schema NOT NULL (default 'trial').
     *
     * @return array<string, mixed>
     */
    private function companySummary(): array
    {
        $company = $this->resource->company;

        return [
            'name' => $company->name,
            'br_number' => $company->br_number,
            'epf_number' => $company->epf_number,
            'plan' => $company->current_billing_plan?->value,
            'status' => $company->status->value,
        ];
    }
}
