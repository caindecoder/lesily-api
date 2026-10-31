<?php

namespace App\Billing\Invoices\UseCases\Commands;

use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;
use App\Billing\Invoices\Entities\Invoice;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;

/**
 * The receipt-download request (UC-1-121) - both values derive from trusted
 * context (auth.company + the route parameter), so neither carries a rule and
 * the receipt contract carries NO 422 (api-1-invoices s2): an unknown invoice
 * id surfaces from the cast as the 404 no-leak response. rules() stays, empty,
 * for the placement gate's every-controller-command clause.
 */
class DownloadInvoiceReceiptCommand extends Data
{
    public function __construct(
        #[WithCast(ModelCast::class, Company::class)]
        public Company $company,
        #[WithCast(ModelCast::class, Invoice::class)]
        public Invoice $invoice,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [];
    }
}
