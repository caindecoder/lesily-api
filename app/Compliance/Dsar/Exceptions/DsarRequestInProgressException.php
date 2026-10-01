<?php

namespace App\Compliance\Dsar\Exceptions;

use Exception;

class DsarRequestInProgressException extends Exception
{
    public function __construct()
    {
        parent::__construct('A data export is already being prepared.');
    }
}
