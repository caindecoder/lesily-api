<?php

namespace App\Companies\Memberships\IO\Http\Resources;

use Illuminate\Http\Request;
use App\Foundation\Http\Responses\ApiErrorCode;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Companies\Memberships\UseCases\Results\BulkInviteRowResult;

/**
 * @property-read BulkInviteRowResult $resource
 */
class BulkInvitationResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sent = $this->resource->wasSent();

        return [
            'email' => $this->resource->email,
            'role_code' => $this->resource->roleCode,
            'status' => $sent ? 'sent' : 'skipped',
            'error_code' => $sent ? null : ApiErrorCode::AlreadyMember->value,
            'message' => $this->resource->skippedBecause,
        ];
    }
}
