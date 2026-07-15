<?php

namespace App\Billing\Invoices\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Billing\Invoices\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Billing\Invoices\UseCases\Commands\InvoiceFilters;
use App\Billing\Invoices\IO\Http\Resources\InvoiceCollection;
use App\Billing\Invoices\Exceptions\ReceiptNotAvailableException;
use App\Billing\Invoices\Exceptions\CorruptReceiptSnapshotException;
use App\Billing\Invoices\UseCases\Renderers\ReceiptPdfRendererInterface;
use App\Billing\Invoices\UseCases\Repositories\InvoiceRepositoryInterface;

/**
 * The tenant invoice read surface — the invoice list (UC-1-120); the receipt
 * download (UC-1-121) joins here at T5. Pure reads, no interactor (cd-invoices
 * §5, gate I1) — the delivery layer translates the query string and presents
 * the read model (conv-plan §2.15 read chain).
 */
class InvoicesController
{
    public function __construct(
        private readonly InvoiceRepositoryInterface $invoices,
        private readonly ReceiptPdfRendererInterface $receiptRenderer,
    ) {}

    public function index(Request $request): InvoiceCollection
    {
        $filters = InvoiceFilters::fromQuery($request);

        return new InvoiceCollection(
            $this->invoices->listForCompany($request->company()->getKey(), $filters)
        );
    }

    public function receipt(Request $request, string $invoiceId): Response
    {
        $invoice = $this->invoices->findForCompany($request->company()->getKey(), $invoiceId);

        // Unknown id and cross-tenant id surface identically (UC-1-121 4a
        // no-existence-leak) — findForCompany is tenant-scoped, so both are null here.
        if ($invoice === null) {
            throw new ModelNotFoundException;
        }

        if (! in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Refunded], true)) {
            throw ReceiptNotAvailableException::notPaid($invoice->status);
        }

        if ($invoice->has_dispute) {
            throw ReceiptNotAvailableException::openDispute();
        }

        // Snapshot integrity gate BEFORE the renderer: a receipt-eligible row
        // missing its paid timestamp is a data bug, not a rendering outage —
        // generic 500, reported with the invoice id (UC-1-121 7c; s135 F-e).
        if ($invoice->paid_at === null) {
            throw CorruptReceiptSnapshotException::missingPaidTimestamp($invoice->getKey());
        }

        $pdf = $this->receiptRenderer->render($invoice);

        // Manual binary headers per the LD ICS-feed idiom — regenerated on
        // demand, nothing persisted (R3; api-1-invoices §4.5).
        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="receipt-'.$invoice->invoice_number.'.pdf"',
        ]);
    }
}
