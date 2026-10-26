<?php

namespace App\Consent\Entities;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * One accepted policy version, per person, per company (UC-1-069). Written only
 * by ConsentRecordProjector and never updated or deleted.
 *
 * @property string $id
 * @property int $stored_event_id
 * @property string $user_id
 * @property string $company_id
 * @property string $policy_type
 * @property string $version
 * @property string $ip_address
 * @property CarbonImmutable $consented_at
 */
class ConsentRecord extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'stored_event_id',
        'user_id',
        'company_id',
        'policy_type',
        'version',
        'ip_address',
        'consented_at',
    ];

    protected $casts = [
        'stored_event_id' => 'integer',
        'consented_at' => 'immutable_datetime',
    ];
}
