<?php

namespace App\Billing\Subscriptions\Entities;

use App\Companies\Entities\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Billing\Subscriptions\Enums\SubscriptionStatus;

/**
 * Trial-slice read model for the subscription.
 *
 * The full Subscription is an event-sourced aggregate (cd-subscriptions, App\Billing\
 * Subscriptions\Aggregates\Subscription) whose projector writes this same `subscriptions`
 * table. For this slice only the trial-stage columns exist; the plan is NULL during trial
 * (set on subscribe per ERD-1-03), and later lifecycle columns land with their increments.
 *
 * @property-read Company $company
 */
class Subscription extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'company_id',
        'status',
        'trial_ends_at',
        'ended_source',
        'cancelled_at',
    ];

    protected $casts = [
        'status' => SubscriptionStatus::class,
        'trial_ends_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
