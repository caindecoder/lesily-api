<?php

namespace App\Billing\Invoices\Exceptions;

use DomainException;

class InvoiceDisputeNotAllowedException extends DomainException
{
    public static function alreadyOpen(string $invoiceId): self
    {
        return new self("Invoice {$invoiceId} already has an open dispute.");
    }

    public static function forStatus(string $invoiceId, string $status): self
    {
        return new self("Invoice {$invoiceId} cannot be disputed while {$status}.");
    }
}
