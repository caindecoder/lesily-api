<?php

namespace App\Foundation\AuditTrail\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Foundation\AuditTrail\Entities\AuditEntry;

/**
 * @mixin AuditEntry
 */
class AuditEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
        ];
    }
}
