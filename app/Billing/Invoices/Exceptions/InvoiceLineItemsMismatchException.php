<?php

namespace App\Billing\Invoices\Exceptions;

use DomainException;

class InvoiceLineItemsMismatchException extends DomainException
{
    public static function forInvoice(string $invoiceId, int $amountCents, int|float $lineItemsTotalCents): self
    {
        $lineItemsTotal = var_export($lineItemsTotalCents, true);

        return new self(
            "Invoice {$invoiceId} line items sum to {$lineItemsTotal} cents but the invoice amount is {$amountCents} cents.",
        );
    }
}
