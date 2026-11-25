<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Companies\Enums\ResettableSetting;

final class EnumOutsideController
{
    public function reset(ResettableSetting $setting): string
    {
        return $setting->value;
    }
}
