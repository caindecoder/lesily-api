<?php

namespace App\Compliance\Dsar\UseCases\Sections;

use App\Models\User;

class ProfileSection implements DsarSectionProvider
{
    public function key(): string
    {
        return 'profile';
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(string $subjectUserId, string $companyId): array
    {
        $user = User::query()->findOrFail($subjectUserId);

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'email_verified_at' => $user->email_verified_at?->toISOString(),
            'last_logged_at' => $user->last_logged_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            'google_account_id' => $user->google_id,
        ];
    }
}
