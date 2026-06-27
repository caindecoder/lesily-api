<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The login payload (UC-1-002 / api-1-auth-onboarding §login). Shape-level rules
 * only and deliberately lenient — a too-short or oddly-shaped password is a 401
 * credential failure, not a 422, so the login seam never discloses the password
 * policy. The credential check itself lives in LogInInteractor.
 *
 * `deviceName` names the issued token for the Active Sessions list (sessions slice). It is
 * always present on the command: the controller resolves it (supplied name → User-Agent →
 * "Unknown device") before validating, so a too-long or absent client value never reaches a
 * 422 — the request-body field stays optional while the command field is authoritative.
 */
class LoginCommand extends Data
{
    public string $email;

    public string $password;

    public string $deviceName;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'deviceName' => ['required', 'string'],
        ];
    }
}
