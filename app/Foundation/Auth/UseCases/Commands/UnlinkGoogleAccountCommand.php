<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The password a user re-enters to unlink Google (UC-1-003 P2 / api-1-users DELETE /me/google).
 * Spatie infers `required|string` from the typed property, which is the whole contract; the
 * password is verified by UnlinkGoogleAccountInteractor.
 */
class UnlinkGoogleAccountCommand extends Data
{
    public string $password;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [];
    }
}
