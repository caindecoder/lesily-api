<?php

namespace App\Billing\Invoices\IO\Http\Resources;

use Illuminate\Http\Request;
use App\Billing\Invoices\Entities\Invoice;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The platform-admin view of one invoice (UC-1-122) — full designed shape per
 * api-1-invoices §3 admin row + the s137 F-c contract pin (B5-style: every
 * designed key emitted; composites null until their producing slices).
 *
 * @property-read Invoice $resource
 */
class InvoiceAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
        ];
    }
}
