<?php

namespace App\Compliance\Breach\Exceptions;

use DomainException;

class BreachIncidentAlreadyRecordedException extends DomainException
{
    public static function withId(string $breachId): self
    {
        return new self("Breach incident {$breachId} has already been recorded.");
    }
}
