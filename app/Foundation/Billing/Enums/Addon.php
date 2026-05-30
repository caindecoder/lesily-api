<?php

namespace App\Foundation\Billing\Enums;

/**
 * Purchasable add-ons. Each grants extra features on top of the company's plan
 * (foundation-brief § "Plans, Billing & Feature Gating"); canUseFeature checks
 * the plan first, then active add-ons.
 */
enum Addon: string
{
    case TemplateEngine = 'template_engine';
    case ReportEngine = 'report_engine';
    case Bundle = 'bundle';

    /**
     * @return array<int, Feature>
     */
    public function features(): array
    {
        return match ($this) {
            self::TemplateEngine => [Feature::TemplateEngine],
            self::ReportEngine => [],
            self::Bundle => [],
        };
    }
}
