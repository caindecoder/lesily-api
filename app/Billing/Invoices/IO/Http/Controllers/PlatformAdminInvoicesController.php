<?php

namespace App\Billing\Invoices\IO\Http\Controllers;

use App\Billing\Invoices\IO\Http\Resources\InvoiceAdminResource;
use App\Billing\Invoices\UseCases\Interactors\ReviewInvoiceAsAdminInteractor;

/**
 * The platform-admin cross-tenant invoice read (UC-1-122) — behind
 * auth:sanctum + platform.admin (B2 INTERIM allowlist; 404 no-info-leak),
 * deliberately NO auth.company. The delivery layer translates and presents
 * only; the cross-tenant find + the ONE audit_trail insert live in the
 * interactor (cd-invoices §5 — s129 R8/E07).
 */
class PlatformAdminInvoicesController
{
    public function show(string $invoiceId, ReviewInvoiceAsAdminInteractor $interactor): InvoiceAdminResource
    {
        return new InvoiceAdminResource($interactor->execute($invoiceId));
    }
}
