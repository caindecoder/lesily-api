<?php

namespace App\Compliance\Breach\Exceptions;

use DomainException;

class NothingToAmendException extends DomainException
{
    public static function everyValueMatches(): self
    {
        return new self('Nothing to amend: every value sent matches the incident.');
    }
}
