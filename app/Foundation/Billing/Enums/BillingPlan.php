<?php

namespace App\Foundation\Billing\Enums;

enum BillingPlan: string
{
    case Free = 'free';
    case Starter = 'starter';
    case Pro = 'pro';
    case Enterprise = 'enterprise';

    /**
     * @return list<Feature>
     */
    public function features(): array
    {
        return match ($this) {
            self::Free => [],
            self::Starter => [],
            self::Pro => [],
            self::Enterprise => [],
        };
    }
}
