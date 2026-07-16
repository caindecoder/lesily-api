<?php

namespace App\Billing\Invoices\UseCases\Responses;

/**
 * The use case's response model (E07): the rendered receipt bytes plus the
 * contract filename source - the delivery layer only wraps these in HTTP.
 */
class InvoiceReceiptPdf
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $invoiceNumber,
    ) {}

    public function fileName(): string
    {
        return "receipt-{$this->invoiceNumber}.pdf";
    }
}
