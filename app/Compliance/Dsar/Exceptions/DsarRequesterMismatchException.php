<?php

namespace App\Compliance\Dsar\Exceptions;

use Exception;

class DsarRequesterMismatchException extends Exception
{
    public function __construct()
    {
        parent::__construct('Only the original requester can withdraw this DSAR.');
    }
}
