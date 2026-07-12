<?php

namespace App\Foundation\Billing\Enums;

/**
 * The four subscription tiers. Plans grant features hierarchically
 * (Enterprise ⊃ Pro ⊃ Starter ⊃ Free) via the spread in features(), mirroring
 * LeaveDates' BillingPlan::getFeatures() (src/domain/Billing/BillingPlan.php).
 */
enum BillingPlan: string
{
    case Free = 'free';
    case Starter = 'starter';
    case Pro = 'pro';
    case Enterprise = 'enterprise';

    /**
     * @return array<int, Feature>
     */
    public function features(): array
    {
        return match ($this) {
            self::Free => [],
            self::Starter => [Feature::EmployeeDirectory],
            self::Pro => [...self::Starter->features(), Feature::TwoFactorAuth],
            // Enterprise adds nothing over Pro in chunk-1; returns Pro's set directly
            // (a re-spread [...] would be a no-op). Becomes [...self::Pro->features(), …]
            // when an Enterprise-only feature lands.
            self::Enterprise => self::Pro->features(),
        };
    }

    /**
     * The lowest tier whose plan grants the feature, or null when no plan does
     * (e.g. an add-on-only or deprecated feature) — drives the upgrade prompt.
     *
     * Relies on the cases being declared in ascending tier order
     * (Free < Starter < Pro < Enterprise); self::cases() preserves that order.
     */
    public static function lowestPlanWith(Feature $feature): ?self
    {
        foreach (self::cases() as $plan) {
            if (in_array($feature, $plan->features(), strict: true)) {
                return $plan;
            }
        }

        return null;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Whether this plan carries the concurrent-device cap (UC-1-002 alt-5a / S14):
     * Pro and Enterprise enforce the 3-device limit; Free and Starter are uncapped.
     */
    public function enforcesDeviceLimit(): bool
    {
        return match ($this) {
            self::Pro, self::Enterprise => true,
            self::Free, self::Starter => false,
        };
    }

    /**
     * The per-user monthly price in LKR integer cents (§10 pricing / §2 money-as-
     * integers): Free 0, Starter 350, Pro 600, Enterprise 750 — ×100.
     */
    public function monthlyPriceCents(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Starter => 35_000,
            self::Pro => 60_000,
            self::Enterprise => 75_000,
        };
    }

    /**
     * The per-user monthly price in LKR integer cents when billed annually, or
     * null where no annual option exists (§10: Starter is monthly-only; Free has
     * nothing to discount): Pro 510 (15% off), Enterprise 600 (20% off) — ×100.
     */
    public function annualPriceCents(): ?int
    {
        return match ($this) {
            self::Free, self::Starter => null,
            self::Pro => 51_000,
            self::Enterprise => 60_000,
        };
    }
}
