<?php

namespace App\Companies\Memberships\UseCases\Results;

use App\Companies\Entities\CompanyUser;

/**
 * The accept outcome for an already-authenticated existing user (UC-1-019 alt
 * flow): they joined the company on their current session — no token is minted
 * (R9/D7). One arm of the interactor's concrete union (s090 idiom); the
 * new-email genesis arm (NewUserJoined, with the issued session) lands with
 * the happy-path lap.
 */
class ExistingUserJoined
{
    public function __construct(
        public readonly CompanyUser $membership,
    ) {}
}
