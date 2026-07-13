<?php

namespace App\Billing\Invoices\Entities;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Model;
use App\Billing\Invoices\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Invoice read model (ERD-1-04), written exclusively by InvoiceProjector — the
 * full Invoice is the event-sourced aggregate (cd-invoices, App\Billing\Invoices\
 * Aggregates\Invoice). No SoftDeletes: status is the source of truth for a gone
 * invoice (voided/refunded are terminal); rows are never physically deleted
 * (Companies Act 10-yr retention, anonymized on Company hard-delete per R-038).
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'id',
        'company_id',
        'subscription_id',
        'invoice_number',
        'status',
        'source',
        'has_dispute',
        'amount_cents',
        'currency',
        'due_date',
        'issued_at',
        'paid_at',
        'payhere_transaction_id',
        'failed_at',
        'failure_count',
        'last_failure_code',
        'voided_at',
        'voided_by',
        'void_reason',
        'void_source',
        'refunded_at',
        'refund_amount_cents',
        'payhere_refund_transaction_id',
        'refund_reason',
        'refund_source',
        'recipient_name',
        'recipient_email',
        'recipient_billing_address',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => InvoiceStatus::class,
        'has_dispute' => 'boolean',
        'amount_cents' => 'integer',
        'refund_amount_cents' => 'integer',
        'failure_count' => 'integer',
        'due_date' => 'immutable_date',
        'issued_at' => 'immutable_datetime',
        'paid_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
        'voided_at' => 'immutable_datetime',
        'refunded_at' => 'immutable_datetime',
        'recipient_billing_address' => 'array',
    ];

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }
}
