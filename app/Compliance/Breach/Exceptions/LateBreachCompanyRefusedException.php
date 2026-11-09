<?php

namespace App\Compliance\Breach\Exceptions;

use DomainException;

class LateBreachCompanyRefusedException extends DomainException
{
    public static function incidentResolved(): self
    {
        return new self('A company cannot be added to a resolved breach incident.');
    }

    public static function falseAlarm(): self
    {
        return new self('A company cannot be added to a breach incident marked as a false alarm.');
    }

    public static function alreadyOnTheIncident(): self
    {
        return new self('The company is already on this breach incident.');
    }

    public static function notYetNotified(): self
    {
        return new self('A company is added late only after the notices; correct the company list instead.');
    }
}
