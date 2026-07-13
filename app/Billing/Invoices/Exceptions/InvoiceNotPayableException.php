<?php

namespace App\Billing\Invoices\Exceptions;

use DomainException;

class InvoiceNotPayableException extends DomainException
{
    public static function forInvoice(string $invoiceId, string $status): self
    {
        return new self("Invoice {$invoiceId} cannot accept a payment while {$status}.");
    }
}
