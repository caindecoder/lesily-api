<?php

namespace App\Foundation\Auth\IO\Http\Resources;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 202 envelope for a 2FA-required login (UC-1-002 alt-4a / api-1-auth-onboarding): the
 * opaque challenge id + its expiry. NO token is issued here — the client must call
 * /2fa/verify with the challenge id to complete login (#03). A `methods` field is
 * deferred to chunk-2 SMS fallback (chunk-1 is TOTP-only).
 */
class TwoFactorChallengeResource extends JsonResource
{
    public function __construct(
        private readonly string $challengeId,
        private readonly DateTimeInterface $expiresAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'challenge_id' => $this->challengeId,
            'expires_at' => $this->expiresAt,
        ];
    }
}
