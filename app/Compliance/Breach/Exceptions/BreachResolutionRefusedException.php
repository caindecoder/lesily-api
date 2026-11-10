<?php

namespace App\Compliance\Breach\Exceptions;

use DomainException;

class BreachResolutionRefusedException extends DomainException
{
    public static function notNotified(): self
    {
        return new self('A breach incident is resolved only after its companies are notified.');
    }
}
