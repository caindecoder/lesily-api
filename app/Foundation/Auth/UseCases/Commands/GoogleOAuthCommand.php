<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The Google sign-in payload (UC-1-003 / api-1-auth-onboarding POST /auth/google). Spatie infers
 * `required|string` from the typed properties, which is the whole contract; `deviceName` is
 * server-resolved by the controller before validation, as for the password login.
 */
class GoogleOAuthCommand extends Data
{
    public string $idToken;

    public string $deviceName;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [];
    }
}
