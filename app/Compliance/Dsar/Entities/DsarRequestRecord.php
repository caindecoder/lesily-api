<?php

namespace App\Compliance\Dsar\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DsarRequestRecord extends Model
{
    use HasUuids;

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
        'created_by',
    ];

    protected $casts = [
        'status' => DsarRequestStatus::class,
        'requested_at' => 'immutable_datetime',
        'sla_deadline' => 'immutable_datetime',
    ];

    /**
     * @param  Builder<self>  $query
     */
    protected function scopeOpen(Builder $query): void
    {
        $query->where('status', DsarRequestStatus::InProgress);
    }
}
