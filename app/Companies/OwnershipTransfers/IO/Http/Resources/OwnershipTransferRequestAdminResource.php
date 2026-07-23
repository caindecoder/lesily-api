<?php

namespace App\Companies\OwnershipTransfers\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;

/**
 * The platform-admin transfer shape (UC-1-127). A SIBLING of the owner-facing
 * OwnershipTransferRequestResource, never a subclass or a flagged variant: the
 * two serve different actors and will change for different reasons, so their
 * overlap is accidental duplication and must not be unified (E49; Advanced E03
 * §13). Exposes what the owner resource withholds — verification artefacts,
 * verifier notes, admin attribution, forensic actor columns, raw ids, and the
 * target's real email.
 *
 * @property-read OwnershipTransferRequest $resource
 */
class OwnershipTransferRequestAdminResource extends JsonResource
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
