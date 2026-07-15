<?php

namespace App\Billing\Invoices\IO\Http\Controllers;

use RuntimeException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Billing\Invoices\Enums\InvoiceStatus;
use App\Billing\Invoices\UseCases\Commands\InvoiceFilters;
use App\Billing\Invoices\IO\Http\Resources\InvoiceCollection;
use App\Billing\Invoices\Exceptions\ReceiptNotAvailableException;
use App\Billing\Invoices\UseCases\Repositories\InvoiceRepositoryInterface;

/**
 * The tenant invoice read surface — the invoice list (UC-1-120); the receipt
 * download (UC-1-121) joins here at T5. Pure reads, no interactor (cd-invoices
 * §5, gate I1) — the delivery layer translates the query string and presents
 * the read model (conv-plan §2.15 read chain).
 */
class InvoicesController
{
    public function __construct(private readonly InvoiceRepositoryInterface $invoices) {}

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

        if ($invoice->status !== InvoiceStatus::Paid) {
            throw ReceiptNotAvailableException::notPaid($invoice->status);
        }

        if ($invoice->has_dispute) {
            throw ReceiptNotAvailableException::openDispute();
        }

        throw new RuntimeException('Not implemented');
    }
}
