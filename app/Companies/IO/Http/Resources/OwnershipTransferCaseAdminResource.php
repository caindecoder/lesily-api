<?php

namespace App\Companies\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Companies\Entities\OwnershipTransferCase;

/**
 * The admin-facing case read (slice-11R spec §3) — minimal at open (UC-1-140);
 * the queue/detail reads (UC-1-127, T5) extend this same resource.
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
            'companyId' => $this->resource->company_id,
            'status' => $this->resource->status->value,
            'closedOutcome' => $this->resource->closed_outcome?->value,
            'newOwnerUserId' => $this->resource->new_owner_user_id,
            'requestedContext' => $this->resource->requested_context,
            'createdAt' => $this->resource->created_at->toISOString(),
        ];
    }
}
