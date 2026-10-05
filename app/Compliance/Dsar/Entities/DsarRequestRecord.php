<?php

namespace App\Compliance\Dsar\Entities;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use Database\Factories\DsarRequestRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property-read User $subject subject_user_id is NOT NULL + restrictOnDelete
 * @property-read User $requestedBy requested_by is NOT NULL + restrictOnDelete
 */
class DsarRequestRecord extends Model
{
    /** @use HasFactory<DsarRequestRecordFactory> */
    use HasFactory, HasUuids;

    protected $table = 'dsar_requests';

    protected $fillable = [
        'id',
        'company_id',
        'subject_user_id',
        'requested_by',
        'requested_by_role',
        'request_type',
        'status',
        'requested_at',
        'sla_deadline',
        'completed_at',
        'file_path',
        'download_expires_at',
        'cancelled_at',
        'cancelled_by',
        'created_by',
    ];

    protected $casts = [
        'status' => DsarRequestStatus::class,
        'requested_at' => 'immutable_datetime',
        'sla_deadline' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'download_expires_at' => 'immutable_datetime',
        'download_expired_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    protected function scopeWhereCompany(Builder $query, Company $company): void
    {
        $query->where('company_id', $company->getKey());
    }

    /**
     * Requests about $user or made by $user.
     *
     * @param  Builder<self>  $query
     */
    protected function scopeWhereVisibleTo(Builder $query, User $user): void
    {
        $query->where('subject_user_id', $user->getKey())->orWhere('requested_by', $user->getKey());
    }

    protected static function newFactory(): DsarRequestRecordFactory
    {
        return DsarRequestRecordFactory::new();
    }
}
