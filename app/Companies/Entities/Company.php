<?php

namespace App\Companies\Entities;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Model;
use App\Foundation\Billing\Enums\Feature;
use App\Foundation\Billing\Enums\BillingPlan;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Chunk-1 read-model stub for the company.
 *
 * The full Company is an event-sourced aggregate (cd-companies, App\Companies\
 * Aggregates\Company) whose projector will eventually write this same `companies`
 * table. For chunk-1 only the billing-gate slice exists: the current plan and the
 * feature check the two-gate middleware reads.
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'companies';

    protected $fillable = [
        'id',
        'current_billing_plan',
    ];

    protected $casts = [
        'current_billing_plan' => BillingPlan::class,
    ];

    /**
     * The billing gate: a feature is available when the plan grants it.
     * (Add-on coverage is layered in by UC-1-055 test #6.)
     */
    public function canUseFeature(Feature $feature): bool
    {
        return in_array($feature, $this->current_billing_plan->features(), strict: true);
    }

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }
}
