<?php

namespace App\Billing\Invoices\Exceptions;

use DomainException;

class InvoicePaymentAmountMismatchException extends DomainException
{
    public static function forInvoice(string $invoiceId, int $invoiceAmountCents, int $paidAmountCents): self
    {
        return new self(
            "Invoice {$invoiceId} is for {$invoiceAmountCents} cents but the payment reports {$paidAmountCents} cents.",
        );
    }
}
