<?php

namespace App\Foundation\Auth\IO\Http\Resources;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 200 envelope for a successful login: the Sanctum bearer token, its 90-day
 * expiry, and the authenticated user (api-1-auth-onboarding §login, §8.2 {data}).
 * Mirrors RegisterSuccessResource but is pre-company-context, so it carries
 * `expiresAt` and omits the company stub.
 */
class LoginResource extends JsonResource
{
    public function __construct(
        private readonly User $user,
        private readonly string $token,
        private readonly ?DateTimeInterface $expiresAt,
    ) {
        parent::__construct($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->token,
            'expiresAt' => $this->expiresAt,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],
        ];
    }
}
