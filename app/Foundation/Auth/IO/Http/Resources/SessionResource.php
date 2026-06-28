<?php

namespace App\Foundation\Auth\IO\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One active login session in the GET /me/sessions list (UC-1-010): the Sanctum
 * token id plus whether it is the session making this request (api-1-users
 * SessionCollection shape — snake_case).
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
            'device_name' => $this->name,
            'last_used_at' => $this->last_used_at,
            'current_session' => $this->isCurrentSession($request),
        ];
    }

    /**
     * True when this token is the one that authenticated the current request — so the
     * UI can mark "this device". currentAccessToken() is typed to the HasAbilities
     * contract; narrow to a PersonalAccessToken before comparing keys.
     */
    private function isCurrentSession(Request $request): bool
    {
        /** @var User|null $user */
        $user = $request->user();
        $current = $user?->currentAccessToken();

        return $current instanceof PersonalAccessToken
            && $current->getKey() === $this->getKey();
    }
}
