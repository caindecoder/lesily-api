<?php

namespace App\Billing\Invoices\UseCases\Interactors;

use App\Billing\Invoices\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Billing\Invoices\UseCases\Responses\InvoiceReceiptPdf;
use App\Billing\Invoices\Exceptions\ReceiptNotAvailableException;
use App\Billing\Invoices\Exceptions\CorruptReceiptSnapshotException;
use App\Billing\Invoices\UseCases\Renderers\ReceiptPdfRendererInterface;
use App\Billing\Invoices\UseCases\Commands\DownloadInvoiceReceiptCommand;
use App\Billing\Invoices\UseCases\Repositories\InvoiceRepositoryInterface;

/**
 * UC-1-121 use case: resolve the tenant-scoped invoice, enforce the receipt
 * state guards (the UC's extension courses - E07), and produce the rendered
 * receipt as a response model. The controller only translates and presents
 * (conv-plan s2.15-B; relocated from the controller on Chamil's s135 ruling).
 */
class DownloadInvoiceReceiptInteractor
{
    public function __construct(
        private readonly InvoiceRepositoryInterface $invoices,
        private readonly ReceiptPdfRendererInterface $receiptRenderer,
    ) {}

    public function execute(DownloadInvoiceReceiptCommand $command): InvoiceReceiptPdf
    {
        $invoice = $this->invoices->findForCompany($command->companyId, $command->invoiceId);

        // Unknown id and cross-tenant id surface identically (UC-1-121 4a
        // no-existence-leak) - findForCompany is tenant-scoped, so both are null.
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
        // missing its paid timestamp is a data bug, not a rendering outage -
        // generic 500, reported with the invoice id (UC-1-121 7c; s135 F-e).
        if ($invoice->paid_at === null) {
            throw CorruptReceiptSnapshotException::missingPaidTimestamp($invoice->getKey());
        }

        return new InvoiceReceiptPdf(
            bytes: $this->receiptRenderer->render($invoice),
            invoiceNumber: $invoice->invoice_number,
        );
    }
}
