<?php

namespace App\Billing\Invoices\Exceptions;

use DomainException;

class InvoiceRefundAmountMismatchException extends DomainException
{
    public static function forInvoice(string $invoiceId, int $invoiceAmountCents, int $refundAmountCents): self
    {
        return new self(
            "Invoice {$invoiceId} is for {$invoiceAmountCents} cents; chunk-1 refunds are full-amount only, got {$refundAmountCents} cents.",
        );
    }
}
