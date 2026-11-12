<?php

namespace App\Companies\Memberships\UseCases\Audit;

use App\Companies\Entities\Company;

/**
 * A bulk invite's outcome as its batch row records it: the company and how many
 * invitations were sent.
 */
readonly class BulkInviteSent
{
    public function __construct(
        public Company $company,
        public int $count,
    ) {}
}
