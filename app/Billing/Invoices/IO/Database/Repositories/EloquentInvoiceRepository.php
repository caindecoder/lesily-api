<?php

namespace App\Billing\Invoices\IO\Database\Repositories;

use Illuminate\Database\Eloquent\Builder;
use App\Billing\Invoices\Entities\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Billing\Invoices\UseCases\Commands\InvoiceFilters;
use App\Billing\Invoices\UseCases\Repositories\InvoiceRepositoryInterface;

/**
 * The invoice-list reader (UC-1-120) over the InvoiceProjector-maintained
 * `invoices` read model. SQL stays below this boundary (E11).
 */
class EloquentInvoiceRepository implements InvoiceRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, Invoice>
     */
    public function listForCompany(string $companyId, InvoiceFilters $filters): LengthAwarePaginator
    {
        return Invoice::query()
            ->where('company_id', $companyId)
            ->when($filters->status !== null, fn (Builder $query) => $query->where('status', $filters->status))
            ->when($filters->min_amount !== null, fn (Builder $query) => $query->where('amount_cents', '>=', $filters->min_amount))
            ->when($filters->max_amount !== null, fn (Builder $query) => $query->where('amount_cents', '<=', $filters->max_amount))
            ->paginate(perPage: $filters->per_page ?? 15, page: $filters->page ?? 1);
    }
}
