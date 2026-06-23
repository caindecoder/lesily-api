<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The current password a user submits to re-authenticate a sensitive 2FA change —
 * disabling 2FA (UC-1-007). Shape-only validation; the password is *verified* against
 * the stored hash by DisableTwoFactorInteractor (the credential check is the
 * gatekeeper, not the rules — mirrors ConfirmTwoFactorCommand).
 */
class DisableTwoFactorCommand extends Data
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
