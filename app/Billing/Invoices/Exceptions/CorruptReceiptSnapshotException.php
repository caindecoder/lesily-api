<?php

namespace App\Billing\Invoices\Exceptions;

use RuntimeException;

/**
 * A receipt-eligible invoice row failed pre-render snapshot validation (a
 * data-integrity bug - e.g. paid with no paid_at). Deliberately unmapped:
 * the Throwable catch-all renders the generic 500 envelope and the report
 * carries the invoice id for ops triage (UC-1-121 error flow 7c; s135 F-e).
 */
class CorruptReceiptSnapshotException extends RuntimeException
{
    public static function missingPaidTimestamp(string $invoiceId): self
    {
        return new self("Invoice {$invoiceId} is receipt-eligible but its snapshot has no paid_at - integrity violated; receipt not rendered.");
    }
}
