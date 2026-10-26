<?php

namespace App\Companies\Memberships\UseCases\Casts;

use Spatie\LaravelData\Casts\Cast;
use Propaganistas\LaravelPhone\PhoneNumber;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Creation\CreationContext;

/**
 * A Sri Lankan phone number, local or +94, in E.164 form (+94712345678).
 */
class SriLankanE164Cast implements Cast
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): string
    {
        /** @var string $number validated by SriLankanPhoneNumber, or already E.164 */
        $number = $value;

        return (new PhoneNumber($number, 'LK'))->formatE164();
    }
}
