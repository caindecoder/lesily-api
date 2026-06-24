<?php

namespace App\Foundation\Auth\UseCases\Results;

use App\Models\User;

/**
 * The owner-registration response model (E07) crossing back over the use-case boundary to
 * the delivery ring: the materialised owner read-model, their company id, and the issued
 * session (token minted inside the interactor via SessionIssuer, session 090). The controller
 * shapes the 201 resource from it — it no longer re-queries the user or mints the token.
 */
class RegisterResult
{
    public function __construct(
        public readonly User $user,
        public readonly string $companyId,
        public readonly IssuedSession $session,
    ) {}
}
