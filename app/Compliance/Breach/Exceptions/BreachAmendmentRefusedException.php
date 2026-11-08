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

    public static function discoveryDateLocked(): self
    {
        return new self('The discovery date is locked once the companies are notified.');
    }

    public static function severityLocked(): self
    {
        return new self('The severity is locked once the companies are notified.');
    }

    public static function companyListLocked(): self
    {
        return new self('The company list is locked once the companies are notified.');
    }
}
