<?php

namespace App\Billing\Invoices\UseCases\Repositories;

use App\Billing\Invoices\Entities\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Billing\Invoices\UseCases\Commands\InvoiceFilters;

/**
 * Read access to the invoices projection (UC-1-120; cd-invoices §Repositories).
 */
interface InvoiceRepositoryInterface
{
    /**
     * The company's invoice page under the whitelisted filters — tenant-scoped:
     * the caller passes the auth.company id, so no other tenant is reachable.
     *
     * @return LengthAwarePaginator<int, Invoice>
     */
    public function listForCompany(string $companyId, InvoiceFilters $filters): LengthAwarePaginator;

    /**
     * One invoice by id, tenant-scoped — null when the id is unknown OR belongs
     * to another company, so the caller cannot tell the two apart (UC-1-121 4a
     * no-existence-leak; the designed unscoped findById rides T7's admin read).
     */
    public function findForCompany(string $companyId, string $invoiceId): ?Invoice;
}
