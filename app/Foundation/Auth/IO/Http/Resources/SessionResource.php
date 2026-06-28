<?php

namespace App\Foundation\Auth\IO\Http\Resources;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One active login session in the GET /me/sessions list (UC-1-010): the Sanctum
 * token id (api-1-users SessionCollection shape — snake_case).
 *
 * @mixin PersonalAccessToken
 */
class SessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token_id' => $this->getKey(),
        ];
    }
}
