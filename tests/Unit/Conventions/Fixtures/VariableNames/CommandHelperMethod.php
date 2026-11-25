<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use Spatie\LaravelData\Data;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use Spatie\LaravelData\Support\Validation\ValidationContext;

final class CommandHelperMethod extends Data
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [];
    }

    protected static function isFrom(RequestOrigin $origin): bool
    {
        return $origin->ip !== '';
    }
}
