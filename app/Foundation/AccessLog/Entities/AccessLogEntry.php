<?php

namespace App\Foundation\AccessLog\Entities;

use Illuminate\Database\Eloquent\Model;
use App\Foundation\AccessLog\Enums\AccessEvent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Foundation\AccessLog\Enums\AccessEventReason;

/**
 * One access_log row (ERD-1-11): an access outcome, never a data change. Rows are
 * written once; only a folded refusal's occurrences and last_seen_at may move (SL12).
 */
class AccessLogEntry extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** Eloquent would guess access_log_entries; the designed name is singular. */
    protected $table = 'access_log';

    protected $fillable = [
        'company_id',
        'user_id',
        'event',
        'reason',
        'http_method',
        'route',
        'status',
        'error_code',
        'ip_address',
        'user_agent',
        'request_id',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'event' => AccessEvent::class,
        'reason' => AccessEventReason::class,
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
