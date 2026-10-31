<?php

namespace App\Billing\Invoices\Enums;

/**
 * What caused an invoice to be issued, refunded or voided (SM-1-04) — the values the
 * built issue, refund and void paths record today.
 */
enum InvoiceSource: string
{
    case Subscribe = 'subscribe';
    case AdminRefund = 'admin-refund';
    case DisputeResolvedInFavor = 'dispute-resolved-in-favor';
    case AdminCorrection = 'admin-correction';
    case DisputePrePayment = 'dispute-pre-payment';
}
