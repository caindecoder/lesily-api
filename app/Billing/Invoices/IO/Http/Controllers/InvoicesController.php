<?php

namespace App\Billing\Invoices\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Billing\Invoices\UseCases\Commands\InvoiceFilters;

/**
 * The tenant invoice read surface — the invoice list (UC-1-120); the receipt
 * download (UC-1-121) joins here at T5. Pure reads, no interactor (cd-invoices
 * §5, gate I1) — the delivery layer translates the query string and presents
 * the read model (conv-plan §2.15 read chain).
 */
class InvoicesController
{
    public function index(Request $request): JsonResponse
    {
        InvoiceFilters::fromQuery($request);

        return response()->json(['data' => []]);
    }
}
