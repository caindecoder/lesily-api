<?php

namespace App\Foundation\UseCases\Casts;

use Carbon\CarbonPeriod;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Casts\Cast;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Creation\CreationContext;

/**
 * A `from,to` pair of dates becomes the period between them. The property's rules refuse
 * any other shape before the cast runs.
 */
class CarbonPeriodCast implements Cast
{
    /**
     * @param  array<string, mixed>  $properties
     * @param  CreationContext<Data>  $context
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): CarbonPeriod
    {
        /** @var string $value the property's rules accept only a "from,to" string */
        [$from, $to] = explode(',', $value);

        return CarbonPeriod::create(CarbonImmutable::parse($from), CarbonImmutable::parse($to));
    }
}
