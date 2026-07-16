<?php

namespace App\Billing\Invoices\UseCases\Commands;

use Spatie\LaravelData\Data;

/**
 * The receipt-download request (UC-1-121) - both values derive from trusted
 * context (auth.company + the route parameter), the blessed derived-inside
 * construction form. Rules are deliberately shape-only: the receipt contract
 * carries NO 422 (api-1-invoices s2) - a malformed invoice id must flow to
 * the tenant-scoped lookup and surface as the 404 no-leak response.
 */
class DownloadInvoiceReceiptCommand extends Data
{
    public function __construct(
        public string $companyId,
        public string $invoiceId,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'companyId' => ['required', 'string'],
            'invoiceId' => ['required', 'string'],
        ];
    }
}
