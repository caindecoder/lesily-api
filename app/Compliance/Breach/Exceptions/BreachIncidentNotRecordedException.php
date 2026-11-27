<?php

namespace App\Compliance\Breach\Exceptions;

use DomainException;
use App\Compliance\Breach\Enums\BreachStage;

class BreachIncidentNotRecordedException extends DomainException
{
    public function __construct(?BreachStage $breachStage)
    {
        $state = $breachStage === null ? 'not recorded' : $breachStage->value;

        parent::__construct("Only a recorded breach incident can have its companies notified; this incident is {$state}.");
    }
}
