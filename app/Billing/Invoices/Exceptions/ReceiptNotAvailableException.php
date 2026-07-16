<?php

namespace App\Billing\Invoices\Exceptions;

use DomainException;
use App\Billing\Invoices\Enums\InvoiceStatus;

/**
 * The receipt state guard refused the download (UC-1-121 5a/5b) — rendered
 * as the 409 STATE_GUARD_VIOLATION envelope; non-paid refusals carry the
 * stored status for the current_status envelope key (s135 F-c).
 */
class ReceiptNotAvailableException extends DomainException
{
    private function __construct(string $message, public readonly ?string $currentStatus = null)
    {
        parent::__construct($message);
    }

    public static function notPaid(InvoiceStatus $status): self
    {
        return new self('Receipt is only available for paid invoices.', $status->value);
    }

    public static function openDispute(): self
    {
        return new self('Receipt is unavailable while a dispute is open.');
    }
}
