<?php

namespace App\Billing\Invoices\Enums;

/**
 * SM-1-04 lifecycle state for an invoice. Cases land with the transition that
 * first records them (SubscriptionStatus precedent).
 */
enum InvoiceStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Voided = 'voided';
}
