<?php

namespace App\Compliance\Dsar\Exceptions;

use Exception;

class DsarRequestNotInProgressException extends Exception
{
    public function __construct()
    {
        parent::__construct('DSAR is no longer in progress.');
    }
}
