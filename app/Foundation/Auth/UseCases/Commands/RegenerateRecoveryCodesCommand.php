<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The current password a user submits to re-authenticate a sensitive 2FA change —
 * regenerating their recovery codes (UC-1-137). Shape-only validation; the password is
 * *verified* against the stored hash by RegenerateRecoveryCodesInteractor (the credential
 * check is the gatekeeper, not the rules — mirrors DisableTwoFactorCommand).
 */
class RegenerateRecoveryCodesCommand extends Data
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
