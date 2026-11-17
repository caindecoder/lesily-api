<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * A sensitive account change the user confirms by re-entering their current
 * password. Shape-only validation; the password is verified against the stored
 * hash by the change's interactor.
 */
abstract class ConfirmPasswordCommand extends Data
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
