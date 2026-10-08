<?php

namespace App\Compliance\Dsar\Exceptions;

use Exception;

class DsarExportFileMissingException extends Exception
{
    public function __construct(string $requestId)
    {
        parent::__construct("The export file of DSAR request {$requestId} is missing from the dsar disk.");
    }
}
