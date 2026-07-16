<?php

namespace App\Billing\Invoices\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Billing\Invoices\UseCases\Commands\InvoiceFilters;
use App\Billing\Invoices\IO\Http\Resources\InvoiceCollection;
use App\Billing\Invoices\UseCases\Commands\DownloadInvoiceReceiptCommand;
use App\Billing\Invoices\UseCases\Repositories\InvoiceRepositoryInterface;
use App\Billing\Invoices\UseCases\Interactors\DownloadInvoiceReceiptInteractor;

/**
 * The tenant invoice read surface — the invoice list (UC-1-120) and the
 * receipt download (UC-1-121). The delivery layer translates the request and
 * presents the result; the receipt's state guards + rendering live in the
 * interactor (conv-plan §2.15-B chain — Chamil's s135 ruling supersedes the
 * s129 I1 controller-direct blessing for this action).
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

    public function receipt(Request $request, string $invoiceId, DownloadInvoiceReceiptInteractor $interactor): Response
    {
        $receipt = $interactor->execute(DownloadInvoiceReceiptCommand::validateAndCreate([
            'companyId' => $request->company()->getKey(),
            'invoiceId' => $invoiceId,
        ]));

        // Manual binary headers per the LD ICS-feed idiom — regenerated on
        // demand, nothing persisted (R3; api-1-invoices §4.5).
        return response($receipt->bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$receipt->fileName().'"',
        ]);
    }
}
