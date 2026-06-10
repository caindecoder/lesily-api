<?php

namespace App\Companies\Entities;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Membership pivot between a user and a company, carrying the member's
 * role_id. Routing attach() through this pivot mints the char(36) UUID
 * primary key (ERD-1-01, R-042) that the raw pivot statement would skip.
 */
class CompanyUser extends Pivot
{
    use HasUuids;

    protected $table = 'company_user';
}
