<?php

namespace App\Companies\IO\Http\Resources;

use Illuminate\Http\Request;
use App\Companies\Entities\CompanyUser;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The refreshed ownership state after a transfer (UC-1-028 step 7): who owns
 * the company now. Deliberately NOT CompanyResource — that contract excludes
 * owner_id by design (B7); this is the transfer's own minimal read.
 *
 * @property-read CompanyUser $resource
 */
class CompanyOwnerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $owner = $this->resource->user()->sole();

        return [
            'company_id' => $this->resource->company_id,
            'owner' => [
                'id' => $owner->getKey(),
                'name' => $owner->name,
                'email' => $owner->email,
            ],
        ];
    }
}
