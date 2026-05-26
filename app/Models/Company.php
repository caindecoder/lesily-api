<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Model;
use App\Foundation\Billing\Enums\Feature;
use App\Foundation\Billing\Enums\BillingPlan;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'id',
        'current_billing_plan',
    ];

    public function canUseFeature(Feature $feature): bool
    {
        $plan = BillingPlan::from($this->current_billing_plan);
        if (in_array($feature, $plan->features(), strict: true)) {
            return true;
        }
        foreach ($this->activeAddons as $companyAddon) {
            if (in_array($feature, $companyAddon->addon->features(), strict: true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return HasMany<CompanyAddon, $this>
     */
    public function activeAddons(): HasMany
    {
        return $this->hasMany(CompanyAddon::class)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * @return BelongsToMany<User, $this, CompanyUser, 'pivot'>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->using(CompanyUser::class)
            ->withPivot('role_id', 'id')
            ->withTimestamps();
    }

    /**
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return CompanyFactory::new();
    }
}
