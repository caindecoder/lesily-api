<?php

namespace App\Companies\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Companies\Entities\OwnershipTransferCase;

/**
 * The admin-facing case read (slice-11R spec §3) — minimal at open (UC-1-140);
 * the queue/detail reads (UC-1-127, T5) extend this same resource. Response
 * keys are snake_case per the built Resource convention (CompanyOwnerResource,
 * InvoiceAdminResource, the §S21 envelope) — camelCase is the REQUEST-side
 * convention only.
 *
 * @property-read OwnershipTransferCase $resource
 */
class OwnershipTransferCaseAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'company_id' => $this->resource->company_id,
            'status' => $this->resource->status->value,
            'new_owner_user_id' => $this->resource->new_owner_user_id,
            'requested_context' => $this->resource->requested_context,
            'created_at' => $this->resource->created_at->toISOString(),
        ];
    }
}
