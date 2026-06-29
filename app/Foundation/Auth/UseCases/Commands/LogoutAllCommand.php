<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The current password a user submits to re-authenticate logging out all of their other
 * devices (UC-1-011). Shape-only validation; the password is *verified* against the stored
 * hash by RevokeAllSessionsInteractor (the credential check is the gatekeeper, not the
 * rules — mirrors DisableTwoFactorCommand).
 */
class LogoutAllCommand extends Data
{
    public string $password;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }
}
