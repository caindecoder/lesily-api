<?php

namespace App\Billing\Subscriptions\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Billing\Subscriptions\Enums\SubscriptionStatus;

/**
 * Trial-slice read model for the subscription.
 *
 * The full Subscription is an event-sourced aggregate (cd-subscriptions, App\Billing\
 * Subscriptions\Aggregates\Subscription) whose projector writes this same `subscriptions`
 * table. For this slice only the trial-stage columns exist; the plan is NULL during trial
 * (set on subscribe per ERD-1-03), and later lifecycle columns land with their increments.
 */
class Subscription extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'company_id',
        'status',
        'trial_ends_at',
    ];

    protected $casts = [
        'status' => SubscriptionStatus::class,
        'trial_ends_at' => 'immutable_datetime',
    ];
}
