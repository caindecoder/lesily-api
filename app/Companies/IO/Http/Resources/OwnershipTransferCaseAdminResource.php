<?php

namespace App\Companies\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Companies\Entities\OwnershipTransferCase;

/**
 * The admin-facing case read (slice-11R spec §3) — minimal at open (UC-1-140);
 * the queue/detail reads (UC-1-127, T5), the verify response (UC-1-113, T6:
 * + verified_at/verified_by), the execute response (UC-1-093, T7:
 * + executed_at/executed_by) and the reject response (UC-1-128, T8:
 * + rejection_reason_code) extend this same resource. Verification notes,
 * the prose rejection_reason and the internal event-id columns stay OFF
 * the wire by design.
 * Response keys are snake_case per the built Resource convention
 * (CompanyOwnerResource, InvoiceAdminResource, the §S21 envelope) —
 * camelCase is the REQUEST-side convention only.
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
            'closed_outcome' => $this->resource->closed_outcome?->value,
            'rejection_reason_code' => $this->resource->rejection_reason_code?->value,
            'new_owner_user_id' => $this->resource->new_owner_user_id,
            'requested_context' => $this->resource->requested_context,
            'verified_at' => $this->resource->verified_at?->toISOString(),
            'verified_by' => $this->resource->verified_by,
            'executed_at' => $this->resource->executed_at?->toISOString(),
            'executed_by' => $this->resource->executed_by,
            'created_at' => $this->resource->created_at->toISOString(),
        ];
    }
}
