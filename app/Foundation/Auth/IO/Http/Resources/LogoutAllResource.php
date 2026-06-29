<?php

namespace App\Foundation\Auth\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 200 envelope for a successful logout-all (UC-1-011): the number of the user's other sessions
 * that were revoked (api-1-auth-onboarding §logout-all, §8.2 {data}). Mirrors LoginResource's
 * typed-constructor shape; carries only the count.
 */
class LogoutAllResource extends JsonResource
{
    public function __construct(private readonly int $tokensRevoked)
    {
        parent::__construct($tokensRevoked);
    }

    /**
     * @return array<string, int>
     */
    public function toArray(Request $request): array
    {
        return [
            'tokens_revoked' => $this->tokensRevoked,
        ];
    }
}
