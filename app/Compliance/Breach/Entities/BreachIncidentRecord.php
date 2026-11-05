<?php

namespace App\Compliance\Breach\Entities;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use App\Compliance\Breach\Enums\BreachStage;
use Illuminate\Database\Eloquent\Collection;
use App\Compliance\Breach\Enums\BreachSeverity;
use Database\Factories\BreachIncidentRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
 * @property ?CarbonImmutable $deadline_missed_at
 * @property ?CarbonImmutable $companies_notified_at
 * @property ?CarbonImmutable $resolved_at
 * @property ?string $resolved_by
 * @property ?string $root_cause
 * @property ?string $remediation_steps
 * @property ?string $resolution_summary
 * @property ?CarbonImmutable $false_alarm_at
 * @property ?string $false_alarm_by
 * @property ?string $false_alarm_reason
 * @property ?string $false_alarm_findings
 * @property-read Collection<int, BreachIncidentCompany> $companies
 */
class BreachIncidentRecord extends Model
{
    /** @use HasFactory<BreachIncidentRecordFactory> */
    use HasFactory, HasUuids;

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
        'deadline_missed_at' => 'immutable_datetime',
        'companies_notified_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
        'false_alarm_at' => 'immutable_datetime',
    ];

    /**
     * @return HasMany<BreachIncidentCompany, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(BreachIncidentCompany::class, 'breach_incident_id');
    }

    public function totalEstimatedCount(): ?int
    {
        $known = $this->companies->pluck('estimated_count')->filter(fn (?int $count): bool => $count !== null);
        /** @var int $total */
        $total = $known->sum();

        return $known->isEmpty() ? null : $total;
    }

    protected static function newFactory(): BreachIncidentRecordFactory
    {
        return BreachIncidentRecordFactory::new();
    }
}
