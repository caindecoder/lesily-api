<?php

namespace App\Billing\Invoices\UseCases\Interactors;

use App\Billing\Invoices\Entities\Invoice;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Billing\Invoices\UseCases\Repositories\InvoiceRepositoryInterface;

/**
 * The platform-admin cross-tenant invoice review (UC-1-122) — the thin
 * use case behind PlatformAdminInvoicesController@show: the deliberately
 * unscoped find, throwing the generic not-found BEFORE any side effect
 * (no audit row on 404 — the read did not happen).
 */
class ReviewInvoiceAsAdminInteractor
{
    public function __construct(private readonly InvoiceRepositoryInterface $invoices) {}

    public function execute(string $invoiceId): Invoice
    {
        $invoice = $this->invoices->findById($invoiceId);

        if ($invoice === null) {
            throw new ModelNotFoundException;
        }

        return $invoice;
    }
}
