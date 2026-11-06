<?php

namespace App\Compliance\Breach\Exceptions;

use DomainException;

class BreachFilingNotOpenException extends DomainException
{
    public static function alreadyRequested(): self
    {
        return new self('Lesily has already been asked to file this breach for the company.');
    }

    public static function alreadyFiled(): self
    {
        return new self('Lesily has already filed this breach with the regulator for the company.');
    }

    public static function notNotified(): self
    {
        return new self('Only a company notified of this breach can ask Lesily to file it.');
    }

    public static function resolved(): self
    {
        return new self('A resolved breach incident takes no filing request.');
    }
}
