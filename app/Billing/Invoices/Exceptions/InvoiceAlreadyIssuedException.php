<?php

namespace App\Billing\Invoices\Exceptions;

use DomainException;

class InvoiceAlreadyIssuedException extends DomainException
{
    public static function withId(string $invoiceId): self
    {
        return new self("Invoice {$invoiceId} has already been issued.");
    }
}
