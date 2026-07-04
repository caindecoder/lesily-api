<?php

namespace App\Companies\Memberships\Entities;

use App\Companies\Entities\Company;
use Illuminate\Database\Eloquent\Model;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The invitation read model — one row per UserMembership aggregate (id IS the
 * aggregate UUID, shared with the company_user pivot on accept — D5b). Written
 * exclusively by MembershipProjector; pending = accepted_at null and expires_at
 * in the future (UC-1-018/019).
 */
class Invitation extends Model
{
    use HasUuids;

    protected $table = 'invitations';

    protected $fillable = [
        'id',
        'company_id',
        'role_id',
        'email',
        'token_hash',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'revoked_by_user_id',
        'revocation_reason',
    ];

    protected $casts = [
        'expires_at' => 'immutable_datetime',
        'accepted_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
