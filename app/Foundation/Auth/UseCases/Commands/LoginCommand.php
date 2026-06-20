<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The login payload (UC-1-002 / api-1-auth-onboarding §login). Shape-level rules
 * only and deliberately lenient — a too-short or oddly-shaped password is a 401
 * credential failure, not a 422, so the login seam never discloses the password
 * policy. The credential check itself lives in AuthenticateUserInteractor.
 */
class LoginCommand extends Data
{
    public string $email;

    public string $password;

    public ?string $deviceName = null;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
            'deviceName' => ['nullable', 'string'],
        ];
    }
}
