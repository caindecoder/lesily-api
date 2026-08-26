<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The 2FA verify payload (UC-1-005 / api-1-auth-onboarding §2fa/verify): the opaque
 * login challenge id (from the login 202) plus the second factor — a TOTP code OR a
 * recovery code. The endpoint carries either in the single `code` field (it tries TOTP
 * first, then recovery), so the rules are shape-only; VerifyTwoFactorInteractor does the
 * actual credential check, never the rules. `challenge_id` (snake) round-trips the 202.
 */
class VerifyTwoFactorCommand extends Data
{
    #[MapInputName('challenge_id')]
    public string $challengeId;

    public string $code;

    public string $deviceName;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'challenge_id' => ['required', 'string'],
            'code' => ['required', 'string'],
        ];
    }
}
