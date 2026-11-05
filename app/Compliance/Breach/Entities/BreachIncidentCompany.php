<?php

namespace App\Compliance\Breach\Entities;

use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Database\Factories\BreachIncidentCompanyFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property string $id
 * @property string $breach_incident_id
 * @property string $company_id
 * @property list<string> $data_types
 * @property ?int $estimated_count
 * @property CarbonImmutable $added_at
 * @property string $added_by
 * @property ?CarbonImmutable $notified_at
 * @property ?CarbonImmutable $filing_requested_at
 * @property ?string $filing_requested_by
 * @property ?CarbonImmutable $filed_at
 * @property-read Company $company company_id is NOT NULL + restrictOnDelete
 */
class BreachIncidentCompany extends Model
{
    /** @use HasFactory<BreachIncidentCompanyFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'breach_incident_id',
        'company_id',
        'data_types',
        'estimated_count',
        'added_at',
        'added_by',
    ];

    protected $casts = [
        'data_types' => 'array',
        'added_at' => 'immutable_datetime',
        'notified_at' => 'immutable_datetime',
        'filing_requested_at' => 'immutable_datetime',
        'filed_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected static function newFactory(): BreachIncidentCompanyFactory
    {
        return BreachIncidentCompanyFactory::new();
    }
}
