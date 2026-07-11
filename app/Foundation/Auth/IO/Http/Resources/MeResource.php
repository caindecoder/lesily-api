<?php

namespace App\Foundation\Auth\IO\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The lean self-profile shape (UC-1-014, slice-profile B4). Companies live at
 * GET /me/companies and sessions at GET /me/sessions — never embedded here.
 *
 * @mixin User
 */
class MeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
        ];
    }
}
