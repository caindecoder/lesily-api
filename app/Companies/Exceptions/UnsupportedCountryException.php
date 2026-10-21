<?php

namespace App\Companies\Exceptions;

use DomainException;

class UnsupportedCountryException extends DomainException
{
    public static function notSupported(): self
    {
        return new self('Selected country is not supported.');
    }
}
