<?php

namespace App\Compliance\Dsar\Exceptions;

use Exception;

class DsarExportNotReadyException extends Exception
{
    public function __construct()
    {
        parent::__construct('This data export is not ready to download.');
    }
}
