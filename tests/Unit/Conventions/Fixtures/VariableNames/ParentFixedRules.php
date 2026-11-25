<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

final class ParentFixedRules extends Data
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [];
    }
}
