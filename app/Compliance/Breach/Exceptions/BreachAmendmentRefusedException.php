<?php

namespace App\Compliance\Breach\Exceptions;

use DomainException;

class BreachAmendmentRefusedException extends DomainException
{
    public static function incidentResolved(): self
    {
        return new self('A resolved breach incident cannot be amended.');
    }

    public static function falseAlarm(): self
    {
        return new self('A breach incident marked as a false alarm cannot be amended.');
    }
}
