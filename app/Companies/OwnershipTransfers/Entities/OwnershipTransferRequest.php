<?php

namespace App\Companies\OwnershipTransfers\Entities;

use App\Models\User;
use App\Companies\Entities\Company;
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
     * The owning tenant — consumed by the platform-admin resource's company
     * summary (UC-1-127 step 7), which is why it exists again after being cut
     * as unconsumed at PR #115.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
     * The admin queue's ranking (UC-1-127 F1): soonest SLA deadline first, so
     * the most time-pressed transfer leads regardless of state or age. Within
     * one state this equals oldest-first FIFO (a deadline is requested_at plus
     * a fixed window); across states it correctly floats a verified transfer on
     * the 14-day timer above an older requested one on the 30-day timer.
     *
     * Terminal rows carry no deadline, so NULLs are pushed LAST — MySQL sorts
     * NULL first on ASC, which would otherwise put dead history on top when a
     * terminal state is requested explicitly. Stable id tie-break.
     *
     * @param  Builder<self>  $query
     */
    public function scopeByDeadlineFirst(Builder $query): void
    {
        $query->orderByRaw('next_state_transition_at IS NULL')
            ->orderBy('next_state_transition_at')
            ->orderBy('id');
    }

    /**
     * The admin read's eager loads — the §21 N+1 guard for the cross-tenant
     * queue. Kept separate from scopeForCompany's baked-in loads because the
     * admin surface must not depend on the tenant read path (E66 §4).
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithAdminRelations(Builder $query): void
    {
        $query->with(['targetUser', 'currentOwner', 'company']);
    }

    /**
     * The platform-admin queue's default set (UC-1-127 F5): the two OPEN states
     * only, so terminal history never buries actionable work. Derives from the
     * enum's own predicate — one source of truth for "which states are open".
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpenStates(Builder $query): void
    {
        $query->whereIn('status', array_filter(
            OwnershipTransferStatus::cases(),
            fn (OwnershipTransferStatus $status): bool => $status->isOpen(),
        ));
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
