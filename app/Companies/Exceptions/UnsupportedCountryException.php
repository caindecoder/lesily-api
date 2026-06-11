<?php

namespace App\Companies\Exceptions;

use DomainException;

class UnsupportedCountryException extends DomainException
{
    public static function forCountry(string $country): self
    {
        return new self("No country defaults are seeded for [{$country}].");
    }
}
