<?php

namespace App\Companies\OwnershipTransfers\Entities;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Database\Factories\OwnershipTransferRequestFactory;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;

/**
 * The ownership-transfer read model — one row per OwnershipTransferRequest
 * aggregate (id IS the aggregate UUID). Single-table aggregate per ERD-1-05:
 * the full multi-actor lifecycle lives in stage-specific timestamp + actor +
 * reason columns. NO SoftDeletes (terminal states are explicit; rows are kept
 * for audit forensics — ERD-1-05 deviation note). Tenant reads enter through
 * the query scopes below (query-scope over custom builder — s064, PHPStan-L8).
 */
class OwnershipTransferRequest extends Model
{
    /** @use HasFactory<OwnershipTransferRequestFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'ownership_transfer_requests';

    protected $guarded = [];

    protected $casts = [
        'status' => OwnershipTransferStatus::class,
        'verification_artefacts' => 'array',
        'requested_at' => 'immutable_datetime',
        'verified_at' => 'immutable_datetime',
        'executed_at' => 'immutable_datetime',
        'rejected_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'expired_at' => 'immutable_datetime',
        'next_state_transition_at' => 'immutable_datetime',
    ];

    protected static function newFactory(): OwnershipTransferRequestFactory
    {
        return OwnershipTransferRequestFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function currentOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_owner_user_id');
    }

    /**
     * Tenant scope + built-in eager load — the N+1 guard (questionnaire §21)
     * baked into the read path so a list never fires a per-row user query.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForCompany(Builder $query, string $companyId): void
    {
        $query->where('company_id', $companyId)->with(['targetUser', 'currentOwner']);
    }

    /**
     * Narrow to a single SM-1-05 state when the caller passed `?state=`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithState(Builder $query, ?string $state): void
    {
        if ($state !== null) {
            $query->where('status', $state);
        }
    }

    /**
     * Newest-first by default (`-requested_at`); ascending when the caller passed
     * `?sort=requested_at`. Stable id tie-break for deterministic pagination.
     *
     * @param  Builder<self>  $query
     */
    public function scopeApplySort(Builder $query, ?string $sort): void
    {
        $direction = $sort === 'requested_at' ? 'asc' : 'desc';
        $query->orderBy('requested_at', $direction)->orderBy('id');
    }
}
