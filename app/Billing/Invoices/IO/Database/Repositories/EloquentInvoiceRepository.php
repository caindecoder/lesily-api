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
            ->when($filters->within !== null, fn (Builder $query) => $this->applyIssuedWithin($query, (string) $filters->within))
            ->when($filters->min_amount !== null, fn (Builder $query) => $query->where('amount_cents', '>=', $filters->min_amount))
            ->when($filters->max_amount !== null, fn (Builder $query) => $query->where('amount_cents', '<=', $filters->max_amount))
            ->tap(fn (Builder $query) => $this->applySort($query, $filters->sort))
            ->paginate(perPage: $filters->per_page ?? 15, page: $filters->page ?? 1);
    }

    public function findForCompany(string $companyId, string $invoiceId): ?Invoice
    {
        return Invoice::query()
            ->where('company_id', $companyId)
            ->whereKey($invoiceId)
            ->first();
    }

    /**
     * `within=from,to` filters on the issue date, both edges inclusive
     * (api-conventions §10.1 date-range convention; validated upstream by
     * InvoiceFilters, so the two ISO dates are guaranteed here).
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private function applyIssuedWithin(Builder $query, string $within): Builder
    {
        [$from, $to] = explode(',', $within);

        return $query
            ->whereDate('issued_at', '>=', $from)
            ->whereDate('issued_at', '<=', $to);
    }

    /**
     * One whitelisted sort token → its ORDER BY (api-1-invoices §2 — `amount`
     * maps to the amount_cents column).
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private function applySort(Builder $query, ?string $sort): Builder
    {
        $ordered = match ($sort) {
            'issued_at' => $query->orderBy('issued_at'),
            'amount' => $query->orderBy('amount_cents'),
            '-amount' => $query->orderByDesc('amount_cents'),
            // `-issued_at` explicit, and the documented default when absent.
            default => $query->orderByDesc('issued_at'),
        };

        // Stable tie-breaker: equal sort keys must not shuffle rows between
        // page requests of a paginated list (memberships precedent).
        return $ordered->orderBy('id');
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
