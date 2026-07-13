<?php

namespace App\Billing\Invoices\Exceptions;

use DomainException;

class InvoiceNotRefundableException extends DomainException
{
    public static function forInvoice(string $invoiceId, string $status): self
    {
        return new self("Invoice {$invoiceId} cannot be refunded while {$status}.");
    }
}
