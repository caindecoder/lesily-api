<?php

namespace App\Companies\Memberships\UseCases\Audit;

use App\Companies\Entities\CompanyUser;

/**
 * A membership's role move to audit: the role it left, the stream whose newest
 * event caused it, and the actor's role when it is not the actor's live one.
 */
readonly class RoleChange
{
    public function __construct(
        public CompanyUser $membership,
        public string $fromRoleCode,
        public string $causeAggregateUuid,
        public ?string $actorRoleCode = null,
    ) {}
}
