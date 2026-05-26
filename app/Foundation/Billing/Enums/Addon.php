<?php

namespace App\Foundation\Billing\Enums;

enum Addon: string
{
    case TemplateEngine = 'template_engine';
    case ReportEngine = 'report_engine';
    case Bundle = 'bundle';

    /**
     * @return list<Feature>
     */
    public function features(): array
    {
        return match ($this) {
            self::TemplateEngine => [Feature::TemplateEngine],
            self::ReportEngine => [],
            self::Bundle => [Feature::TemplateEngine],
        };
    }
}
