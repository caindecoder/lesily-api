<?php

namespace App\Foundation\Auth\IO\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 201 envelope for the owner signup: the Sanctum token plus the freshly-projected
 * user and first-company stub (api-1-auth-onboarding §register row, §8.2 {data}).
 */
class RegisterSuccessResource extends JsonResource
{
    public function __construct(
        private readonly User $owner,
        private readonly string $token,
        private readonly string $companyId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->token,
            'user' => [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
                'email' => $this->owner->email,
            ],
            'company' => [
                'id' => $this->companyId,
            ],
        ];
    }
}
