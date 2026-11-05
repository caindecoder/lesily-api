<?php

namespace App\Compliance\Breach\Entities;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use App\Compliance\Breach\Enums\BreachStage;
use Illuminate\Database\Eloquent\Collection;
use App\Compliance\Breach\Enums\BreachSeverity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property BreachStage $stage
 * @property BreachSeverity $severity
 * @property string $description
 * @property string $likely_consequences
 * @property string $measures
 * @property CarbonImmutable $date_discovered
 * @property CarbonImmutable $deadline
 * @property CarbonImmutable $recorded_at
 * @property string $recorded_by
 * @property-read Collection<int, BreachIncidentCompany> $companies
 */
class BreachIncidentRecord extends Model
{
    use HasUuids;

    protected $table = 'breach_incidents';

    protected $fillable = [
        'id',
        'stage',
        'severity',
        'description',
        'likely_consequences',
        'measures',
        'date_discovered',
        'deadline',
        'recorded_at',
        'recorded_by',
    ];

    protected $casts = [
        'stage' => BreachStage::class,
        'severity' => BreachSeverity::class,
        'date_discovered' => 'immutable_datetime',
        'deadline' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    /**
     * @return HasMany<BreachIncidentCompany, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(BreachIncidentCompany::class, 'breach_incident_id');
    }
}
