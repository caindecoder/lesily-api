<?php

namespace App\Compliance\Dsar\UseCases\Sections;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class SignInsSection implements DsarSectionProvider
{
    public function key(): string
    {
        return 'sign_ins';
    }

    /**
     * Every sign-in token the subject holds, oldest first — tokens belong to
     * the account, not to a company — and never their hash.
     *
     * @return array<int, array<string, string|null>>
     */
    public function collect(string $subjectUserId, string $companyId): array
    {
        return User::query()->findOrFail($subjectUserId)
            ->tokens()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (PersonalAccessToken $token): array => [
                'device_name' => $token->name,
                'signed_in_at' => $token->created_at?->toISOString(),
                'last_used_at' => $token->last_used_at?->toISOString(),
                'expires_at' => $token->expires_at?->toISOString(),
            ])
            ->all();
    }
}
