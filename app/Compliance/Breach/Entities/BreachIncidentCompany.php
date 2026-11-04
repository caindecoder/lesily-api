<?php

namespace App\Compliance\Breach\Entities;

use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $breach_incident_id
 * @property string $company_id
 * @property list<string> $data_types
 * @property ?int $estimated_count
 * @property CarbonImmutable $added_at
 * @property string $added_by
 * @property-read Company $company company_id is NOT NULL + restrictOnDelete
 */
class BreachIncidentCompany extends Model
{
    use HasUuids;

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
    ];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
