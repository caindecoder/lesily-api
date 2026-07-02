<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Genesis payload for the UserMembership aggregate (SP-style internal command —
 * CreateCompanyCommand parallel): the interactor resolves the role and mints the
 * plaintext token; the aggregate hashes it and stamps the expiry, so the
 * plaintext never enters the event stream (D3).
 */
class CreateInvitationCommand extends Data
{
    public string $companyId;

    public string $email;

    public string $roleId;

    public string $invitedByUserId;

    public string $plaintextToken;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        // max:255 bounds the varchar-backed fields at the command boundary — an
        // over-long value would record a UserInvited event whose projection can
        // never succeed (poison event on replay; CreateCompanyCommand precedent).
        return [
            'companyId' => ['required', 'uuid'],
            'email' => ['required', 'email', 'max:255'],
            'roleId' => ['required', 'uuid'],
            'invitedByUserId' => ['required', 'uuid'],
            'plaintextToken' => ['required', 'string', 'min:40', 'max:255'],
        ];
    }
}
