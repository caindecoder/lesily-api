<?php

namespace App\Companies\OwnershipTransfers\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;

/**
 * The owner-facing transfer-request shape (CD-1-05 §3, snake_case per the
 * built convention): lifecycle basics only — verification artefacts and admin
 * attribution stay on the admin resource (UC-1-127, HideSensitiveAttributes
 * concern). Task-2 reads extend with queue_position / expected_completion_at.
 *
 * @property-read OwnershipTransferRequest $resource
 */
class OwnershipTransferRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'company_id' => $this->resource->company_id,
            'status' => $this->resource->status,
            'request_channel' => $this->resource->request_channel,
            'reason' => $this->resource->reason,
            'requested_at' => $this->resource->requested_at,
        ];
    }
}
