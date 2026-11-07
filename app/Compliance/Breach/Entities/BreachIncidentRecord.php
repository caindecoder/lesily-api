<?php

namespace App\Compliance\Breach\Entities;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
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
 * @property-read int $companies_count the queue's withCount
 * @property-read string|int|null $companies_sum_estimated_count the queue's withSum — the driver returns a decimal string; null when no count is known
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

    /**
     * @param  Builder<self>  $query
     */
    protected function scopeWhereStage(Builder $query, ?BreachStage $stage): void
    {
        $query->when($stage !== null, fn (Builder $query) => $query->where('stage', $stage));
    }

    /**
     * @param  Builder<self>  $query
     */
    protected function scopeOrderByDeadline(Builder $query): void
    {
        $query->orderBy('deadline')->orderBy('id');
    }

    /**
     * @param  Builder<self>  $query
     */
    protected function scopeWhereDeadlineMissedToFlag(Builder $query, CarbonInterface $at): void
    {
        $query->where('deadline', '<=', $at);
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
