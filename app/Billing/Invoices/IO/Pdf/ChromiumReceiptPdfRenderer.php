<?php

namespace App\Billing\Invoices\IO\Pdf;

use App\Billing\Invoices\Entities\Invoice;
use App\Billing\Invoices\Exceptions\ReceiptRenderingFailedException;
use App\Billing\Invoices\UseCases\Renderers\ReceiptPdfRendererInterface;

/**
 * The Chromium adapter behind the receipt-rendering port (s135 F-a, R-052) —
 * the only class allowed to name the rendering library (E05 adapter firewall).
 * Every render-time failure is translated to the app-owned exception so the
 * 503 contract stays library-agnostic.
 */
class ChromiumReceiptPdfRenderer implements ReceiptPdfRendererInterface
{
    public function render(Invoice $invoice): string
    {
        throw new ReceiptRenderingFailedException('Chromium renderer not yet wired — the happy-path lap implements it.');
    }
}
