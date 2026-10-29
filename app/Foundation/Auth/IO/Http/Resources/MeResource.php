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
            'id' => $this->getKey(),
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'locale' => $this->locale,
            'two_factor_enabled' => $this->two_factor_confirmed_at !== null,
            'google_linked' => $this->google_id !== null,
            'default_company_id' => $this->liveDefaultCompanyId(),
            'last_logged_at' => $this->last_logged_at,
            'created_at' => $this->created_at,
        ];
    }
}
