<?php

namespace App\Compliance\Breach\Entities;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\BreachAmendmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Compliance\Breach\Enums\BreachAmendmentField;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property string $id
 * @property string $breach_incident_id
 * @property ?string $company_id
 * @property BreachAmendmentField $field
 * @property mixed $old_value
 * @property mixed $new_value
 * @property string $reason
 * @property CarbonImmutable $amended_at
 * @property string $amended_by
 * @property ?CarbonImmutable $created_at
 */
class BreachAmendment extends Model
{
    /** @use HasFactory<BreachAmendmentFactory> */
    use HasFactory, HasUuids;

    public const ?string UPDATED_AT = null;

    protected $fillable = [
        'breach_incident_id',
        'company_id',
        'field',
        'old_value',
        'new_value',
        'reason',
        'amended_at',
        'amended_by',
    ];

    protected $casts = [
        'field' => BreachAmendmentField::class,
        'old_value' => 'json',
        'new_value' => 'json',
        'amended_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    protected static function newFactory(): BreachAmendmentFactory
    {
        return BreachAmendmentFactory::new();
    }
}
