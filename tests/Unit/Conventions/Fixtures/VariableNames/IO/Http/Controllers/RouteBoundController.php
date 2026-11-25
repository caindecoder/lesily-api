<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames\IO\Http\Controllers;

use App\Companies\Enums\ResettableSetting;

final class RouteBoundController
{
    public function destroy(ResettableSetting $setting): string
    {
        return $setting->value;
    }
}
