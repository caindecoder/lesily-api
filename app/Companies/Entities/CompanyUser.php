<?php

namespace App\Companies\Entities;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Membership pivot between a user and a company, carrying the member's
 * role_id. Routing attach() through this pivot mints the char(36) UUID
 * primary key (ERD-1-01, R-042) that the raw pivot statement would skip.
 *
 * Soft-deletes model removal (UC-1-024, D5): the global scope applies ONLY to
 * CompanyUser-model queries — belongsToMany reads (Company::users() etc.) must
 * filter the pivot column explicitly (wherePivotNull('deleted_at')).
 */
class CompanyUser extends Pivot
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'company_user';
}
