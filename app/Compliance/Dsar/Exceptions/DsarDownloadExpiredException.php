<?php

namespace App\Compliance\Dsar\Exceptions;

use Exception;

class DsarDownloadExpiredException extends Exception
{
    public function __construct()
    {
        parent::__construct('This data export has expired. Request a fresh export.');
    }
}
