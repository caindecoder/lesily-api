<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The TOTP code a member submits to confirm 2FA enrollment (UC-1-006 step 4).
 * Shape-only validation — the code is *verified* against the staged secret by
 * ConfirmTwoFactorInteractor (the credential check is the gatekeeper, not the rules).
 */
class ConfirmTwoFactorCommand extends Data
{
    public string $code;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'code' => ['required', 'string'],
        ];
    }
}
