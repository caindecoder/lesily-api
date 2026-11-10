<?php

namespace App\Compliance\Breach\Exceptions;

use DomainException;

class BreachFalseAlarmRefusedException extends DomainException
{
    public static function notRecorded(): self
    {
        return new self('Only a recorded breach incident can be marked a false alarm.');
    }

    public static function companiesNotified(): self
    {
        return new self('A breach incident is marked a false alarm only before its companies are notified.');
    }

    public static function incidentResolved(): self
    {
        return new self('A resolved breach incident cannot be marked a false alarm.');
    }

    public static function alreadyMarked(): self
    {
        return new self('A breach incident already marked as a false alarm cannot be marked again.');
    }
}
