<?php

namespace App\Companies\Entities;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\Pivot;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership pivot between a user and a company, carrying the member's
 * role_id. Routing attach() through this pivot mints the char(36) UUID
 * primary key (ERD-1-01, R-042) that the raw pivot statement would skip.
 *
 * Soft-deletes model removal (UC-1-024, D5): the global scope applies ONLY to
 * CompanyUser-model queries — belongsToMany reads (Company::users() etc.) must
 * filter the pivot column explicitly (wherePivotNull('deleted_at')).
 *
 * @property string $id
 * @property string $company_id
 * @property string $user_id
 * @property string $role_id
 * @property ?string $invitation_id
 * @property ?string $emergency_contact_name
 * @property ?string $emergency_contact_email
 * @property ?string $emergency_contact_phone
 * @property ?string $emergency_contact_relationship
 * @property bool $onboarding_completed
 * @property bool $onboarding_skipped
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
class CompanyUser extends Pivot
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'company_user';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'emergency_contact_name' => 'encrypted',
            'emergency_contact_email' => 'encrypted',
            'emergency_contact_phone' => 'encrypted',
            'emergency_contact_relationship' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
