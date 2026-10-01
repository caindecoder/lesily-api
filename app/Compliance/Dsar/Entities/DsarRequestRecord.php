<?php

namespace App\Compliance\Dsar\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use Database\Factories\DsarRequestRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
        'created_by',
    ];

    protected $casts = [
        'status' => DsarRequestStatus::class,
        'requested_at' => 'immutable_datetime',
        'sla_deadline' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'download_expires_at' => 'immutable_datetime',
    ];

    protected static function newFactory(): DsarRequestRecordFactory
    {
        return DsarRequestRecordFactory::new();
    }

    /**
     * @param  Builder<self>  $query
     */
    protected function scopeOpen(Builder $query): void
    {
        $query->where('status', DsarRequestStatus::InProgress);
    }
}
