<?php

namespace App\Billing\Invoices\UseCases\Renderers;

use App\Billing\Invoices\Entities\Invoice;
use App\Billing\Invoices\Exceptions\ReceiptRenderingFailedException;

/**
 * The receipt-rendering port (UC-1-121) — the use-case side owns this seam;
 * the rendering library lives behind it as an IO plugin (E11/E05 adapter
 * firewall; s135 F-a: Chromium via spatie/laravel-pdf + Browsershot, R-052).
 * Rendering is a transient projection of the invoice's frozen snapshot —
 * nothing is persisted (R3).
 */
interface ReceiptPdfRendererInterface
{
    /**
     * The invoice's receipt as raw PDF bytes, regenerated on this call.
     *
     * @throws ReceiptRenderingFailedException when the renderer cannot produce
     *                                         a complete PDF (mapped to the 503
     *                                         envelope — never a truncated stream)
     */
    public function render(Invoice $invoice): string;
}
