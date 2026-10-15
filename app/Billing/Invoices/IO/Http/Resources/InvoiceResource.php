<?php

namespace App\Billing\Invoices\IO\Http\Resources;

use Illuminate\Http\Request;
use App\Billing\Invoices\Entities\Invoice;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One invoice-list row (UC-1-120) — exactly the eight contract keys per
 * slice-plans-billing §2. Owner-facing: admin-only and recipient columns never
 * leave this seam (exact-envelope leak-guard, spec §6). `number` and `due_at`
 * are the contract names for the stored invoice_number / due_date columns.
 *
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'number' => $this->invoice_number,
            'issued_at' => $this->issued_at->toJSON(),
            'due_at' => $this->due_date->toDateString(),
            'status' => $this->status->value,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'has_dispute' => $this->has_dispute,
        ];
    }
}
