<?php

namespace App\Companies\Entities;

use App\Models\User;
use App\Companies\Enums\CompanyStatus;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Model;
use App\Foundation\Billing\Enums\Feature;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
        'name',
        'br_number',
        'epf_number',
        'country',
        'owner_id',
        'current_billing_plan',
        'status',
        'trial_ends_at',
    ];

    protected $casts = [
        'current_billing_plan' => BillingPlan::class,
        'status' => CompanyStatus::class,
        'trial_ends_at' => 'immutable_datetime',
    ];

    /**
     * The billing gate: a feature is available when the plan grants it, or when an
     * active add-on does (plan checked first, then add-ons).
     */
    public function canUseFeature(Feature $feature): bool
    {
        $planFeatures = $this->current_billing_plan?->features() ?? [];

        if (in_array($feature, $planFeatures, strict: true)) {
            return true;
        }

        foreach ($this->addons as $companyAddon) {
            if (in_array($feature, $companyAddon->addon->features(), strict: true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return HasMany<CompanyAddon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(CompanyAddon::class, 'company_id');
    }

    /**
     * Members of this company; the pivot carries the member's role_id, which the
     * PermissionEvaluator resolves to evaluate the role gate. attach() routes
     * through the CompanyUser pivot so its UUID primary key is minted;
     * withTimestamps() stamps the pivot's created_at — the member's join date the
     * roster sorts on (UC-1-017 ?sort=-created_at).
     *
     * @return BelongsToMany<User, $this, CompanyUser>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user', 'company_id', 'user_id')
            ->using(CompanyUser::class)
            ->withPivot('role_id')
            ->withTimestamps();
    }

    /**
     * The single member holding the Owner role (UC-1-049 owner notification, ownership
     * transfer) — null when no owner is linked. `owner` is one system-seeded role; `roles.code`
     * is unique, so `findByCode` resolves it unambiguously (a null id matches no pivot row).
     */
    public function owner(): ?User
    {
        return $this->users()->wherePivot('role_id', Role::findByCode('owner')?->id)->first();
    }

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }
}
