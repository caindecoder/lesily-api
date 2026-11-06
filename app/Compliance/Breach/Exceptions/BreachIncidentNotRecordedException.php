<?php

namespace App\Compliance\Breach\Exceptions;

use DomainException;
use App\Compliance\Breach\Enums\BreachStage;

class BreachIncidentNotRecordedException extends DomainException
{
    public function __construct(?BreachStage $stage)
    {
        $state = $stage === null ? 'not recorded' : $stage->value;

        parent::__construct("Only a recorded breach incident can have its companies notified; this incident is {$state}.");
    }
}
