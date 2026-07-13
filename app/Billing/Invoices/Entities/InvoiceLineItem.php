<?php

namespace App\Billing\Invoices\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Immutable invoice line item (ERD-1-04) — inserted with its parent invoice by
 * InvoiceProjector on InvoiceCreated, never updated (corrections = void + new
 * invoice). created_at only; amount_cents is signed (proration credits).
 */
class InvoiceLineItem extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'company_id',
        'invoice_id',
        'description',
        'quantity',
        'unit_price_cents',
        'amount_cents',
        'currency',
        'line_type',
        'reference_id',
        'reference_type',
        'period_start',
        'period_end',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price_cents' => 'integer',
        'amount_cents' => 'integer',
        'sort_order' => 'integer',
        'period_start' => 'immutable_datetime',
        'period_end' => 'immutable_datetime',
    ];
}
