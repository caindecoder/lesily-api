<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;

/**
 * Accept a team invitation (UC-1-019). The token arrives via the ROUTE param —
 * the controller folds it into the payload (H6 session 109); the body carries
 * only name + password, which are meaningful on the new-user branch alone (the
 * invitee email lives on the invitation row, never in the request). Shape rules
 * live here; PRESENCE of name/password on the new-email branch is the
 * interactor's call — it depends on invitee existence, unknowable pre-lookup.
 */
class AcceptInvitationCommand extends Data
{
    public function __construct(
        public string $token,
        public ?string $password = null,
        public ?string $name = null,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'password' => ['nullable', 'string', 'min:8'],
            'name' => ['nullable', 'string'],
        ];
    }
}
