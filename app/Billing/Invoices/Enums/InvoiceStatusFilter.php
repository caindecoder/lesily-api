<?php

namespace App\Billing\Invoices\Enums;

/**
 * The invoice list's status filter (api-1-invoices §2): SM-1-04's five stored states
 * plus the two derived views — overdue (pending past its due date) and disputed.
 */
enum InvoiceStatusFilter: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Voided = 'voided';
    case Overdue = 'overdue';
    case Disputed = 'disputed';
}
