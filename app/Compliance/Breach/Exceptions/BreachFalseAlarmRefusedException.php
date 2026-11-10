<?php

namespace App\Compliance\Breach\Exceptions;

use DomainException;

class BreachFalseAlarmRefusedException extends DomainException
{
    public static function companiesNotified(): self
    {
        return new self('A breach incident is marked a false alarm only before its companies are notified.');
    }
}
