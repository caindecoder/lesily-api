<?php

namespace App\Compliance\Breach\Exceptions;

use DomainException;

class BreachResolutionRefusedException extends DomainException
{
    public static function notNotified(): self
    {
        return new self('A breach incident is resolved only after its companies are notified.');
    }

    public static function beforeTheDeadline(): self
    {
        return new self('A breach incident is resolved only after its 72-hour deadline has passed.');
    }

    public static function filingPending(): self
    {
        return new self('A breach incident is resolved only once every filing request has been filed.');
    }

    public static function incidentResolved(): self
    {
        return new self('A resolved breach incident cannot be resolved again.');
    }

    public static function falseAlarm(): self
    {
        return new self('A breach incident marked as a false alarm cannot be resolved.');
    }
}
