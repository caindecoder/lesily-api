<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The invite request: the invitee's email and the role code to assign
 * (UC-1-018). Shape-only validation — the already-member and role guards live in
 * InviteUserInteractor.
 */
class InviteUserCommand extends Data
{
    public string $email;

    public string $roleCode;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'email' => ['required', 'email'],
            'roleCode' => ['required', 'string'],
        ];
    }
}
