<?php

namespace App\Billing\Invoices\IO\Database\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use App\Billing\Invoices\Entities\Invoice;
use App\Billing\Invoices\Enums\InvoiceStatus;
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
            ->when($filters->status !== null, fn (Builder $query) => $this->applyStatusToken($query, (string) $filters->status))
            ->when($filters->min_amount !== null, fn (Builder $query) => $query->where('amount_cents', '>=', $filters->min_amount))
            ->when($filters->max_amount !== null, fn (Builder $query) => $query->where('amount_cents', '<=', $filters->max_amount))
            ->paginate(perPage: $filters->per_page ?? 15, page: $filters->page ?? 1);
    }

    /**
     * One whitelisted status token → its query arm. The stored SM-1-04 states
     * match the status column; `overdue` is the DERIVED view — pending past its
     * due date — never a stored value (s129 R4).
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private function applyStatusToken(Builder $query, string $status): Builder
    {
        return match ($status) {
            'overdue' => $query
                ->where('status', InvoiceStatus::Pending)
                ->whereDate('due_date', '<', CarbonImmutable::today()),
            'disputed' => $query->where('has_dispute', true),
            default => $query->where('status', $status),
        };
    }
}
