<?php

namespace App\Billing\Invoices\Exceptions;

use DomainException;

class InvoiceAmountNotPositiveException extends DomainException
{
    public static function forInvoice(string $invoiceId, int $amountCents): self
    {
        return new self("Invoice {$invoiceId} cannot be issued for {$amountCents} cents; the amount must be positive.");
    }
}
