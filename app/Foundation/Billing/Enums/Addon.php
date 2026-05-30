<?php

namespace App\Foundation\Billing\Enums;

/**
 * Purchasable add-ons. Each grants extra features on top of the company's plan
 * (foundation-brief § "Plans, Billing & Feature Gating"); canUseFeature checks
 * the plan first, then active add-ons.
 *
 * Chunk-1 defines only TemplateEngine (the one with a gated feature). The other
 * planned add-ons (ReportEngine, Bundle) land with the add-on-purchase UC, when
 * their features and tests exist.
 */
enum Addon: string
{
    case TemplateEngine = 'template_engine';

    /**
     * @return array<int, Feature>
     */
    public function features(): array
    {
        return match ($this) {
            self::TemplateEngine => [Feature::TemplateEngine],
        };
    }
}
