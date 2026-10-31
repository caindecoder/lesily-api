<?php

namespace App\Billing\Invoices\Enums;

/**
 * What caused an invoice to be issued, refunded or voided (SM-1-04). Cases land with
 * the producer that first records them.
 */
enum InvoiceSource: string
{
    case Subscribe = 'subscribe';
    case AdminRefund = 'admin-refund';
    case DisputeResolvedInFavor = 'dispute-resolved-in-favor';
    case AdminCorrection = 'admin-correction';
    case DisputePrePayment = 'dispute-pre-payment';
}
